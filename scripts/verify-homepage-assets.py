#!/usr/bin/env python3
"""Read only the public homepage, empty Cart and fixed reviewed theme assets."""
import hashlib
from html.parser import HTMLParser
import io
import importlib.util
import json
import os
import re
from pathlib import Path
import ssl
import sys
from urllib.error import HTTPError, URLError
from urllib.request import HTTPSHandler, Request, build_opener
from urllib.parse import parse_qs, urlsplit
import zlib
from PIL import Image

ROOT=Path(__file__).resolve().parents[1]
spec=importlib.util.spec_from_file_location('asset_http',ROOT/'scripts/verify-account-navigation-assets.py')
http=importlib.util.module_from_spec(spec);spec.loader.exec_module(http)
http.MAX_BYTES=8388608
ASSETS={'product_payment_script_http_verified':'assets/js/payment-layout.js',
    'product_payment_stylesheet_http_verified':'assets/css/payment-layout.css',
    'cart_payment_script_http_verified':'assets/js/cart-payment-layout.js',
    'cart_payment_stylesheet_http_verified':'assets/css/cart-payment-layout.css',
    'cart_typography_stylesheet_http_verified':'assets/css/cart-typography.css',
    'account_dropdown_script_http_verified':'assets/js/account-dropdown.js',
    'account_dropdown_stylesheet_http_verified':'assets/css/account-dropdown.css',
    'hero_editor_script_http_verified':'assets/js/hero-images-admin.js',
    'cart_badge_script_http_verified':'assets/js/cart-badge.js',
    'cart_badge_stylesheet_http_verified':'assets/css/cart-badge.css','theme_script_http_verified':'assets/js/theme.js',
    'theme_stylesheet_http_verified':'assets/css/petish.css',
    'market_script_http_verified':'assets/js/market-selector.js',
    'market_stylesheet_http_verified':'assets/css/market-selector.css',
    'country_flag_sprite_http_verified':'assets/images/ui/country-flags.svg',
    'country_flag_license_http_verified':'assets/images/ui/country-flags.LICENSE.txt',
    'home_stylesheet_http_verified':'assets/css/home.css',
    'for_your_pet_script_http_verified':'assets/js/for-your-pet.js',
    'free_delivery_badge_http_verified':'assets/images/ui/free-delivery-van.png'}
CHECKS={'account_dropdown_public_config_verified','cart_badge_public_config_verified','homepage_http_verified','hot_subtitle_absent','theme_switch_public_markup_verified',
    'homepage_country_flags_verified','cart_http_verified','cart_country_flags_verified',*ASSETS}


class HeaderParser(HTMLParser):
    """Bounded element-only tree; do not retain page text or execute scripts."""
    void={'area','base','br','col','embed','hr','img','input','link','meta','param','source','track','wbr'}
    def __init__(self):
        super().__init__(convert_charrefs=True)
        self.root={'tag':'root','attrs':{},'children':[],'parent':None}
        self.stack=[self.root];self.switches=[];self.markets=[];self.count=0
    def handle_starttag(self,tag,attrs):
        self.count+=1
        if self.count>50000:raise ValueError()
        attrs={key:value for key,value in attrs if key in {'class','id','type','role','aria-label','aria-checked','aria-hidden',
            'focusable','width','height','viewbox','href','data-petish-theme-switch','data-petish-theme-toggle','data-petish-market'}}
        node={'tag':tag,'attrs':attrs,'children':[],'parent':self.stack[-1]}
        self.stack[-1]['children'].append(node)
        if 'data-petish-theme-switch' in attrs:self.switches.append(node)
        if 'data-petish-market' in attrs:self.markets.append(node)
        if len(self.switches)>20 or len(self.markets)>20 or len(self.stack)>256:raise ValueError()
        if tag not in self.void:self.stack.append(node)
    def handle_endtag(self,tag):
        for index in range(len(self.stack)-1,0,-1):
            if self.stack[index]['tag']==tag:
                del self.stack[index:];break
    def handle_startendtag(self,tag,attrs):
        self.handle_starttag(tag,attrs)
        if tag not in self.void:self.handle_endtag(tag)


def classes(node):return set((node['attrs'].get('class') or '').split())


def in_header(node):
    while node is not None:
        if classes(node)&{'header-bar','mobile-header-bar'} or node['attrs'].get('id')=='phantom':return True
        node=node['parent']
    return False


def theme_switch_markup(body):
    """Check only public static header structure; never execute page scripts."""
    try:
        parser=HeaderParser();parser.feed(body);parser.close()
        if not parser.switches:return False
        for wrapper in parser.switches:
            siblings=wrapper['parent']['children'];position=next(i for i,item in enumerate(siblings) if item is wrapper)
            if position==0 or position+1>=len(siblings) or 'mini-search' not in classes(siblings[position-1]) \
                    or 'data-petish-market' not in siblings[position+1]['attrs']:return False
            if not in_header(wrapper):return False
            buttons=[node for node in wrapper['children'] if node['tag']=='button' and 'data-petish-theme-toggle' in node['attrs']]
            if len(buttons)!=1:return False
            attrs=buttons[0]['attrs']
            if attrs.get('type')!='button' or attrs.get('role')!='switch' or attrs.get('aria-checked') not in {'true','false'} \
                    or not (attrs.get('aria-label') or '').strip():return False
        return True
    except (ValueError,TypeError,KeyError,StopIteration,AssertionError):return False


def country_flag_markup(body,known_symbols):
    """Verify every rendered market uses the reviewed local sprite, not emoji."""
    try:
        parser=HeaderParser();parser.feed(body);parser.close()
        if not parser.markets or not {'flag-au','flag-globe'}<=known_symbols:return False
        for market in parser.markets:
            if not in_header(market):return False
            queue=list(market['children']);flags=[]
            while queue:
                node=queue.pop();queue.extend(node['children'])
                if 'petish-market__flag' in classes(node):flags.append(node)
            if len(flags)!=1 or flags[0]['tag']!='svg':return False
            attrs=flags[0]['attrs']
            if attrs.get('aria-hidden')!='true' or attrs.get('focusable')!='false' \
                    or attrs.get('viewbox')!='0 0 512 512' or attrs.get('width') not in {'20','22'} \
                    or attrs.get('height')!=attrs.get('width'):return False
            children=flags[0]['children']
            if len(children)!=1 or children[0]['tag']!='use':return False
            target=urlsplit(children[0]['attrs'].get('href') or '')
            query=parse_qs(target.query,keep_blank_values=True)
            if target.scheme!='https' or target.netloc!='petish.biz' \
                    or target.path!='/wp-content/themes/dt-the7-child/assets/images/ui/country-flags.svg' \
                    or target.fragment not in known_symbols or set(query)!={'ver'} or len(query['ver'])!=1 \
                    or not re.fullmatch(r'[0-9]{1,20}',query['ver'][0]):return False
        return True
    except (ValueError,TypeError,KeyError,AssertionError):return False


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
    known_symbols=set(re.findall(r'<symbol\s+[^>]*\bid="(flag-(?:[a-z]{2}|globe))"',
        expected['assets/images/ui/country-flags.svg'].decode('utf-8')))
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
        report['checks']['homepage_country_flags_verified']=country_flag_markup(body,known_symbols)
        report['checks']['cart_badge_public_config_verified']='window.petishCartBadge' in body and 'petish_cart_count' in body and re.search(r'class=[\"\'][^\"\']*\bpetish-cart-badge\b',body) is None
        report['checks']['account_dropdown_public_config_verified']='window.petishAccountDropdown' in body and 'petish_account_dropdown' in body \
            and re.search(r'class=[\"\'][^\"\']*\bpetish-account-menu\b',body) is not None \
            and re.search(r'\bdata-petish-account(?=[\s=>])',body) is not None \
            and re.search(r'<li\b[^>]*\bclass=[\"\'][^\"\']*\bpetish-account-menu\b',body,re.I) is None \
            and re.search(r'class=[\"\'][^\"\']*\bpetish-account-avatar\b',body) is None
    except (HTTPError,URLError,TimeoutError,OSError):failures.append('http')
    except (ValueError,UnicodeError,zlib.error):failures.append('assets')
    # A fresh anonymous GET checks Cart's rendered header. No cookie jar is
    # installed and no add-to-cart, checkout or market AJAX request is sent.
    try:
        url='https://petish.biz/cart/'
        request=Request(url,method='GET',headers={'Accept-Encoding':'identity','User-Agent':'Petish-homepage-verifier/1.0'})
        with opener.open(request,timeout=15) as response:
            if response.status!=200 or response.geturl()!=url:raise ValueError()
            body=http.decoded_body(response).decode('utf-8')
        report['checks']['cart_http_verified']='woocommerce' in body.lower() and 'cart' in body.lower()
        report['checks']['cart_country_flags_verified']=country_flag_markup(body,known_symbols)
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
