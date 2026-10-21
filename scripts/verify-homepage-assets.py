#!/usr/bin/env python3
"""Read only the public homepage and five fixed reviewed theme assets."""
import hashlib
from html.parser import HTMLParser
import io
import importlib.util
import json
import os
from pathlib import Path
import ssl
import sys
from urllib.error import HTTPError, URLError
from urllib.request import HTTPSHandler, Request, build_opener
import zlib
from PIL import Image

ROOT=Path(__file__).resolve().parents[1]
spec=importlib.util.spec_from_file_location('asset_http',ROOT/'scripts/verify-account-navigation-assets.py')
http=importlib.util.module_from_spec(spec);spec.loader.exec_module(http)
http.MAX_BYTES=8388608
ASSETS={'theme_script_http_verified':'assets/js/theme.js',
    'theme_stylesheet_http_verified':'assets/css/petish.css',
    'home_stylesheet_http_verified':'assets/css/home.css',
    'for_your_pet_script_http_verified':'assets/js/for-your-pet.js',
    'free_delivery_badge_http_verified':'assets/images/ui/free-delivery-van.png'}
CHECKS={'homepage_http_verified','hot_subtitle_absent','theme_switch_public_markup_verified',*ASSETS}


def theme_switch_markup(body):
    """Check only public static header structure; never execute page scripts."""
    class HeaderParser(HTMLParser):
        void={'area','base','br','col','embed','hr','img','input','link','meta','param','source','track','wbr'}
        def __init__(self):
            super().__init__(convert_charrefs=True)
            self.root={'tag':'root','attrs':{},'children':[],'parent':None}
            self.stack=[self.root];self.switches=[];self.count=0
        def handle_starttag(self,tag,attrs):
            self.count+=1
            if self.count>50000:raise ValueError()
            attrs={key:value for key,value in attrs if key in {'class','id','type','role','aria-label','aria-checked','data-petish-theme-switch','data-petish-theme-toggle','data-petish-market'}}
            node={'tag':tag,'attrs':attrs,'children':[],'parent':self.stack[-1]}
            self.stack[-1]['children'].append(node)
            if 'data-petish-theme-switch' in attrs:self.switches.append(node)
            if len(self.switches)>20 or len(self.stack)>256:raise ValueError()
            if tag not in self.void:self.stack.append(node)
        def handle_endtag(self,tag):
            for index in range(len(self.stack)-1,0,-1):
                if self.stack[index]['tag']==tag:
                    del self.stack[index:];break
        def handle_startendtag(self,tag,attrs):
            self.handle_starttag(tag,attrs)
            if tag not in self.void:self.handle_endtag(tag)
    try:
        parser=HeaderParser();parser.feed(body);parser.close()
        if not parser.switches:return False
        classes=lambda node:set((node['attrs'].get('class') or '').split())
        for wrapper in parser.switches:
            siblings=wrapper['parent']['children'];position=next(i for i,item in enumerate(siblings) if item is wrapper)
            if position==0 or position+1>=len(siblings) or 'mini-search' not in classes(siblings[position-1]) \
                    or 'data-petish-market' not in siblings[position+1]['attrs']:return False
            ancestor=wrapper['parent'];header=False
            while ancestor is not None:
                header=header or bool(classes(ancestor)&{'header-bar','mobile-header-bar'}) or ancestor['attrs'].get('id')=='phantom'
                ancestor=ancestor['parent']
            if not header:return False
            buttons=[node for node in wrapper['children'] if node['tag']=='button' and 'data-petish-theme-toggle' in node['attrs']]
            if len(buttons)!=1:return False
            attrs=buttons[0]['attrs']
            if attrs.get('type')!='button' or attrs.get('role')!='switch' or attrs.get('aria-checked') not in {'true','false'} \
                    or not (attrs.get('aria-label') or '').strip():return False
        return True
    except (ValueError,TypeError,KeyError,StopIteration,AssertionError):return False


def png_matches(expected,fetched):
    """Allow lossless encoding changes only; dimensions and RGBA must agree."""
    try:
        with Image.open(io.BytesIO(expected)) as original,Image.open(io.BytesIO(fetched)) as public:
            if original.format!='PNG' or public.format!='PNG' or original.size!=public.size \
                or not 0<original.width<=4096 or not 0<original.height<=4096 \
                or getattr(original,'n_frames',1)!=1 or getattr(public,'n_frames',1)!=1:return False
            if hashlib.sha256(expected).digest()==hashlib.sha256(fetched).digest():return True
            return original.convert('RGBA').tobytes()==public.convert('RGBA').tobytes()
    except (OSError,ValueError,Image.DecompressionBombError):return False


def verify(opener,expected):
    report={'status':'failure','category':'input','checks':dict.fromkeys(sorted(CHECKS),False)}
    failures=[]
    for check,name in ASSETS.items():
        body=expected[name];digest=hashlib.sha256(body).hexdigest()
        url='https://petish.biz/wp-content/themes/dt-the7-child/'+name+'?ver='+digest
        try:
            request=Request(url,headers={'Accept-Encoding':'identity','User-Agent':'Petish-homepage-verifier/1.0'})
            with opener.open(request,timeout=15) as response:
                if response.status!=200 or response.geturl()!=url:raise ValueError()
                fetched=http.decoded_body(response)
            matched=png_matches(body,fetched) if name.endswith('.png') else hashlib.sha256(fetched).digest()==hashlib.sha256(body).digest()
            if not matched:
                failures.append('assets');continue
            report['checks'][check]=True
        except (HTTPError,URLError,TimeoutError,OSError):failures.append('http')
        except (ValueError,zlib.error):failures.append('assets')
    try:
        url='https://petish.biz/'
        request=Request(url,headers={'Accept-Encoding':'identity','User-Agent':'Petish-homepage-verifier/1.0'})
        with opener.open(request,timeout=15) as response:
            if response.status!=200 or response.geturl()!=url:raise ValueError()
            body=http.decoded_body(response).decode('utf-8')
        report['checks']['homepage_http_verified']='petish-home-title' in body
        report['checks']['hot_subtitle_absent']='Selected by supplier-reported orders.' not in body
        report['checks']['theme_switch_public_markup_verified']=theme_switch_markup(body)
    except (HTTPError,URLError,TimeoutError,OSError):failures.append('http')
    except (ValueError,UnicodeError,zlib.error):failures.append('assets')
    if all(report['checks'].values()):report.update(status='success',category='complete')
    else:report['category']='http' if 'http' in failures else 'assets'
    return report


def main(arguments):
    if len(arguments)!=1:return 1
    os.umask(0o077)
    report={'status':'failure','category':'input','checks':dict.fromkeys(sorted(CHECKS),False)}
    try:
        theme=ROOT/'wordpress/wp-content/themes/dt-the7-child'
        expected={name:http.source_bytes(theme/name) for name in ASSETS.values()}
        opener=build_opener(http.NoRedirect(),HTTPSHandler(context=ssl.create_default_context()))
        report=verify(opener,expected)
    except (OSError,ValueError):pass
    try:http.write_report(Path(arguments[0]),report)
    except (OSError,ValueError):return 1
    return 0 if report['status']=='success' else 1


if __name__=='__main__':raise SystemExit(main(sys.argv[1:]))
