"""Publish bounded page metadata and text already visible to anonymous visitors."""
from html.parser import HTMLParser
import json, os, re, ssl, sys
from pathlib import Path
from urllib.request import Request, build_opener, HTTPSHandler, HTTPRedirectHandler

class NoRedirect(HTTPRedirectHandler):
    def redirect_request(self, *args, **kwargs):
        return None

class PublicCopy(HTMLParser):
    def __init__(self):
        super().__init__(); self.depth=0; self.capture=None; self.skip=0; self.parts=[]; self.found=False
    def handle_starttag(self,tag,attrs):
        attrs=dict(attrs)
        if tag not in {'area','base','br','col','embed','hr','img','input','link','meta','param','source','track','wbr'}:
            self.depth+=1
        if self.capture is None and attrs.get('id')=='content':
            self.capture=self.depth; self.found=True
        if self.capture is not None and tag in {'script','style','noscript','form','nav'}: self.skip+=1
        if self.capture is not None and not self.skip and tag in {'p','h1','h2','h3','li','br'}: self.parts.append('\n')
    def handle_endtag(self,tag):
        if tag in {'area','base','br','col','embed','hr','img','input','link','meta','param','source','track','wbr'}: return
        if self.capture is not None and tag in {'script','style','noscript','form','nav'}: self.skip=max(0,self.skip-1)
        if self.capture==self.depth: self.capture=None
        self.depth=max(0,self.depth-1)
    def handle_data(self,data):
        if self.capture is not None and not self.skip: self.parts.append(data)

def screen(value):
    required={'target_paths_verified','target_environment_verified','woocommerce_runtime_verified',
      'all_outbound_http_blocked','mail_calls_blocked','remote_file_access_disabled',
      'cron_and_async_runner_disabled','production_read_only_verified'}
    if set(value)!={'status','category','checks','page'} or value['status']!='success' or value['category']!='complete' \
       or set(value['checks'])!=required or any(v is not True for v in value['checks'].values()): raise ValueError()
    p=value['page']; fields={'id','content_sha256','content_bytes','template','block_markup','shortcodes_present',
      'elementor_builder','elementor_data_present','object_cache_dropin_present','advanced_cache_dropin_present'}
    if set(p)!=fields or type(p['id']) is not int or not 0<p['id']<2**53 \
       or not isinstance(p['content_sha256'],str) or not re.fullmatch('[a-f0-9]{64}',p['content_sha256']) \
       or type(p['content_bytes']) is not int or not 0<=p['content_bytes']<=131072 \
       or not isinstance(p['template'],str) or not re.fullmatch('[a-zA-Z0-9_./-]{0,160}',p['template']) \
       or any(type(p[k]) is not bool for k in fields-{'id','content_sha256','content_bytes','template'}): raise ValueError()
    return value

if __name__=='__main__':
    os.umask(0o077)
    try:
        if len(sys.argv)!=3: raise ValueError()
        raw=Path(sys.argv[1]); info=raw.lstat()
        if raw.is_symlink() or not raw.is_file() or info.st_nlink!=1 or not 0<info.st_size<=16384: raise ValueError()
        value=screen(json.loads(raw.read_text()))
        url='https://petish.biz/delivery-shipping/'
        opener=build_opener(NoRedirect(),HTTPSHandler(context=ssl.create_default_context()))
        with opener.open(Request(url,headers={'Accept-Encoding':'identity','User-Agent':'Petish-delivery-page-review/1.0'}),timeout=25) as response:
            if response.status!=200 or response.geturl()!=url: raise ValueError()
            value['public_cache']={'litespeed_hit':response.headers.get('X-LiteSpeed-Cache','').strip().lower()=='hit',
              'no_store':'no-store' in response.headers.get('Cache-Control','').lower()}
            body=response.read(1048577)
            if not 0<len(body)<=1048576: raise ValueError()
        parser=PublicCopy(); parser.feed(body.decode('utf-8'))
        text='\n'.join(line.strip() for line in ''.join(parser.parts).splitlines() if line.strip())
        if not parser.found or not 10<len(text)<=16384: raise ValueError()
        value['public_copy']=text
        Path(sys.argv[2]).write_text(json.dumps(value,indent=2)+'\n')
    except Exception:
        print('Delivery-page inspection did not pass its fixed-page checks.',file=sys.stderr);sys.exit(1)
