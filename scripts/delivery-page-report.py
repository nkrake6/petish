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

COMMON={'target_paths_verified','target_environment_verified','woocommerce_runtime_verified',
  'all_outbound_http_blocked','mail_calls_blocked','remote_file_access_disabled','cron_and_async_runner_disabled'}
UPDATE_COMMON=COMMON|{'jetpack_sync_isolated','no_persistent_cache_dropins'}
UPDATE_CHECKS=UPDATE_COMMON|{'production_read_only_verified','transactional_page_table','page_table_has_no_triggers',
  'expected_page_content','native_editable_content_saved','rehearsal_rolled_back','private_before_snapshot_saved',
  'committed_row_verified','rollback_owns_current_row','original_row_restored'}

def diagnostic(value):
    if set(value)-{'status','category','checks','page','native_observations'} or value.get('status')!='failure' \
        or value.get('category') not in {'input','paths','bootstrap','runtime','page','mutation_scope',
          'expected_content','native_update','snapshot','commit','rollback'}: raise ValueError()
    checks=value['checks']
    if not isinstance(checks,dict) or set(checks)-UPDATE_CHECKS or any(type(v) is not bool for v in checks.values()): raise ValueError()
    result={'category':value['category'],'failed_checks':sorted(k for k,v in checks.items() if not v),
      'passed_checks':sum(checks.values()),'rehearsal_rolled_back':checks.get('rehearsal_rolled_back') is True}
    observations=value.get('native_observations',{})
    booleans={'native_pre_update_reached','native_target_valid','expected_field_set','expected_field_order','content_matches',
      'unchanged_fields','valid_modified_dates','valid_field_types','prepared_statement_approved','approved_statement_executed',
      'write_permit_pending','native_result_valid'}
    if not isinstance(observations,dict) or set(observations)-booleans-{'native_field_count','blocked_statement_kind','blocked_table'}: raise ValueError()
    if any(type(observations[k]) is not bool for k in observations.keys()&booleans): raise ValueError()
    if 'native_field_count' in observations and (type(observations['native_field_count']) is not int or not 0<=observations['native_field_count']<=100): raise ValueError()
    if 'blocked_statement_kind' in observations and observations['blocked_statement_kind'] not in {'update','insert','delete','replace','set','other'}: raise ValueError()
    if 'blocked_table' in observations and observations['blocked_table'] not in {'posts','postmeta','options','other'}: raise ValueError()
    result['observations']=observations
    return result

def screen(value, mode='inspect'):
    if mode not in {'inspect','rehearse','apply','rollback'}: raise ValueError()
    if mode != 'inspect':
        required=UPDATE_COMMON|({'transactional_page_table','page_table_has_no_triggers','rollback_owns_current_row','original_row_restored'} if mode=='rollback' else
          {'transactional_page_table','page_table_has_no_triggers','expected_page_content','native_editable_content_saved'}|
          ({'rehearsal_rolled_back'} if mode=='rehearse' else {'private_before_snapshot_saved','committed_row_verified'}))
        if set(value)!={'status','category','checks','page'} or value['status']!='success' or value['category']!='complete' \
            or set(value['checks'])!=required or any(v is not True for v in value['checks'].values()): raise ValueError()
        p=value['page']
        if set(p)!={'id','content_sha256'} or type(p['id']) is not int or p['id']!=1002 \
            or not isinstance(p['content_sha256'],str) or not re.fullmatch('[a-f0-9]{64}',p['content_sha256']): raise ValueError()
        return value
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

def normalize(text):
    return re.sub(r'\s+',' ',text.translate(str.maketrans({'’':"'",'‘':"'",'“':'"','”':'"','—':'-','–':'-'}))).strip()

def public_copy():
    url='https://petish.biz/delivery-shipping/'
    opener=build_opener(NoRedirect(),HTTPSHandler(context=ssl.create_default_context()))
    with opener.open(Request(url,headers={'Accept-Encoding':'identity','User-Agent':'Petish-delivery-page-review/1.0'}),timeout=25) as response:
        if response.status!=200 or response.geturl()!=url: raise ValueError()
        cache={'litespeed_hit':response.headers.get('X-LiteSpeed-Cache','').strip().lower()=='hit',
          'no_store':'no-store' in response.headers.get('Cache-Control','').lower()}
        body=response.read(1048577)
        if not 0<len(body)<=1048576: raise ValueError()
    parser=PublicCopy(); parser.feed(body.decode('utf-8'))
    text='\n'.join(line.strip() for line in ''.join(parser.parts).splitlines() if line.strip())
    if not parser.found or not 10<len(text)<=16384: raise ValueError()
    return text,cache

if __name__=='__main__':
    os.umask(0o077)
    try:
        if len(sys.argv) not in {3,4,5}: raise ValueError()
        mode=sys.argv[3] if len(sys.argv)>3 else 'inspect'
        native_only=len(sys.argv)==5 and sys.argv[4]=='native-only'
        if len(sys.argv)==5 and not native_only: raise ValueError()
        raw=Path(sys.argv[1]); info=raw.lstat()
        if raw.is_symlink() or not raw.is_file() or info.st_nlink!=1 or not 0<info.st_size<=16384: raise ValueError()
        value=screen(json.loads(raw.read_text()),mode)
        if mode!='inspect':
            manifest=json.loads(Path('operations/delivery-page-release.json').read_text())
            expected=manifest['new_content_sha256' if mode=='apply' else 'old_content_sha256']
            if value['page']['content_sha256']!=expected: raise ValueError()
        if not native_only:
            text,cache=public_copy()
            value['public_cache']=cache;value['public_copy']=text
            if mode!='inspect':
                import hashlib
                texts=[text,public_copy()[0]]
                if mode=='apply':
                    source=Path('operations/delivery-page-content.html').read_text()
                    if hashlib.sha256(source.encode()).hexdigest()!=manifest['new_content_sha256']: raise ValueError()
                    parser=PublicCopy();parser.feed('<div id="content">'+source+'</div>')
                    expected=normalize(''.join(parser.parts))
                    if any(normalize(item)!=expected or 'Initial delivery trial:' in item for item in texts): raise ValueError()
                elif any(hashlib.sha256(normalize(item).encode()).hexdigest()!=manifest['old_public_copy_sha256'] for item in texts): raise ValueError()
                value['canonical_public_copy_verified_twice']=True
        Path(sys.argv[2]).write_text(json.dumps(value,indent=2)+'\n')
    except Exception:
        print('Delivery-page operation did not pass its fixed-page checks.',file=sys.stderr);sys.exit(1)
