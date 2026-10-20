#!/usr/bin/env python3
"""Read only the public homepage and two fixed reviewed theme assets."""
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import ssl
import sys
from urllib.error import HTTPError, URLError
from urllib.request import HTTPSHandler, Request, build_opener
import zlib

ROOT=Path(__file__).resolve().parents[1]
spec=importlib.util.spec_from_file_location('asset_http',ROOT/'scripts/verify-account-navigation-assets.py')
http=importlib.util.module_from_spec(spec);spec.loader.exec_module(http)
http.MAX_BYTES=8388608
ASSETS={'home_stylesheet_http_verified':'assets/css/home.css',
    'free_delivery_badge_http_verified':'assets/images/ui/free-delivery-van.png'}
CHECKS={'homepage_http_verified','hot_subtitle_absent',*ASSETS}


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
            if hashlib.sha256(fetched).digest()!=hashlib.sha256(body).digest():
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
