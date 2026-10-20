#!/usr/bin/env python3
"""Fail closed before publishing the four synthetic native email previews.

This validates, rather than repairs, generated HTML. No source text, paths,
private URLs, or parser exceptions are included in its public report.
"""
from html import unescape
from html.parser import HTMLParser
import json
import os
from pathlib import Path
import re
import stat
import sys
from urllib.parse import unquote

FILES = ('processing.html', 'completed.html', 'refunded.html', 'note.html')
MAX_BYTES = 1024 * 1024
ALLOWED_LINKS = {
    'https://petish.biz/', 'https://petish.biz/shop/',
    'https://petish.biz/my-account/', 'https://petish.biz/delivery-shipping/',
    'https://petish.biz/contact/', 'https://petish.biz/privacy-policy/',
    'https://petish.biz/terms-conditions/', 'mailto:orders@petish.biz',
}
ALLOWED_EMAILS = {'orders@petish.biz', 'alex@petish.invalid'}
ALLOWED_TAGS = {
    'html', 'head', 'body', 'title', 'style', 'meta', 'table', 'tbody',
    'thead', 'tfoot', 'tr', 'td', 'th', 'p', 'div', 'span', 'h1', 'h2',
    'h3', 'h4', 'h5', 'h6', 'small', 'strong', 'b', 'i', 'em', 'br',
    'hr', 'a', 'img', 'ul', 'ol', 'li', 'blockquote', 'address', 'del',
    'ins', 'center', 'sup', 'sub',
}
VOID_TAGS = {'meta', 'img', 'br', 'hr'}
GLOBAL_ATTRIBUTES = {
    'class', 'id', 'style', 'align', 'valign', 'width', 'height', 'border',
    'cellpadding', 'cellspacing', 'bgcolor', 'role', 'dir', 'lang',
    'colspan', 'rowspan', 'scope', 'aria-label', 'aria-hidden',
}
EMAIL = re.compile(r"[A-Z0-9.!#$%&'*+/=?^_`{|}~-]+@[A-Z0-9.-]+\.[A-Z]{2,63}", re.I)
SENSITIVE = re.compile(
    r'(?:/home/|/workspace/|/tmp/|wp-config(?:\.php)?|\.petish-staging|'
    r'staging\.petish\.biz|wc_order_|_wpnonce|\bnonce\s*=|'
    r'-----BEGIN[^\r\n]*PRIVATE KEY-----|\bAKIA[A-Z0-9]{16}\b|'
    r'\b(?:sk_live_|sk_test_|pk_live_|gh[pousr]_|github_pat_|GOCSPX-)[A-Za-z0-9_-]{8,}|'
    r'\b(?:DB_PASSWORD|client_secret|api_secret|secret_key|refresh_token|access_token)\b|'
    r'\bBearer\s+[A-Za-z0-9_.+-]{12,}|\bpassword\s*[:=])', re.I)
ACTIVE_TEXT = re.compile(r'(?:javascript\s*:|vbscript\s*:|data\s*:|<\s*(?:script|iframe|form|object|embed)\b)', re.I)
UNSAFE_CSS = re.compile(r'(?:\\|url\s*\(|@import\b|expression\s*\(|behavior\s*:|-moz-binding|javascript\s*:|vbscript\s*:|data\s*:|https?\s*:|//|<)', re.I)


class PreviewRejected(ValueError):
    def __init__(self, category):
        self.category = category
        super().__init__('Email preview rejected.')


def reject(category):
    raise PreviewRejected(category)


def decoded(value):
    for _ in range(3):
        changed = unquote(unescape(value))
        if changed == value:
            break
        value = changed
    return value


def validate_css(value):
    if not isinstance(value, str) or UNSAFE_CSS.search(decoded(value)):
        reject('css')


class PreviewParser(HTMLParser):
    def __init__(self):
        super().__init__(convert_charrefs=True)
        self.stack = []
        self.counts = {}
        self.text = []
        self.styles = []
        self.images = 0
        self.doctype = False

    def handle_decl(self, decl):
        if self.doctype or decl.strip().lower() != 'doctype html':
            reject('html_structure')
        self.doctype = True

    def unknown_decl(self, data):
        reject('html_structure')

    def handle_pi(self, data):
        reject('active_content')

    def handle_comment(self, data):
        # Native renderer removes Outlook conditional comments from shareable
        # copies; they must not smuggle active markup past this parser.
        reject('active_content')

    def handle_starttag(self, tag, attrs):
        if tag not in ALLOWED_TAGS:
            reject('active_content')
        if len(attrs) > 50 or len({name for name, unused in attrs}) != len(attrs):
            reject('attributes')
        allowed = set(GLOBAL_ATTRIBUTES)
        if tag == 'a':
            allowed.add('href')
        if tag == 'img':
            allowed.update({'src', 'alt'})
        if tag == 'meta':
            allowed = {'charset', 'name', 'content'}
        if tag == 'style':
            allowed = {'type'}
        attributes = dict(attrs)
        for name, value in attrs:
            if name.startswith('on') or name not in allowed or value is None \
                    or len(value) > 16384 or any(ord(char) < 32 and char not in '\t\r\n' for char in value):
                reject('attributes')
            if name == 'style':
                validate_css(value)
                self.styles.append(value)
        if tag == 'a' and 'href' in attributes and attributes['href'] not in ALLOWED_LINKS:
            reject('links')
        if tag == 'img':
            if attributes.get('src') != 'petish-logo.png':
                reject('images')
            self.images += 1
        if tag == 'meta':
            if 'charset' in attributes and attributes['charset'].lower() not in {'utf-8', 'utf8'}:
                reject('attributes')
            if 'name' in attributes and attributes['name'].lower() not in {
                'viewport', 'format-detection', 'color-scheme', 'robots',
            }:
                reject('attributes')
        if tag == 'style' and attributes.get('type', 'text/css').lower() != 'text/css':
            reject('css')
        self.counts[tag] = self.counts.get(tag, 0) + 1
        if tag in {'html', 'head', 'body'} and self.counts[tag] > 1:
            reject('html_structure')
        if tag not in VOID_TAGS:
            self.stack.append(tag)

    def handle_startendtag(self, tag, attrs):
        if tag not in VOID_TAGS:
            reject('html_structure')
        self.handle_starttag(tag, attrs)

    def handle_endtag(self, tag):
        if tag in VOID_TAGS or not self.stack or self.stack[-1] != tag:
            reject('html_structure')
        self.stack.pop()

    def handle_data(self, data):
        if self.stack and self.stack[-1] == 'style':
            validate_css(data)
            self.styles.append(data)
        elif 'body' in self.stack:
            self.text.append(data)
        elif data.strip() and not any(tag in self.stack for tag in ('head', 'title')):
            reject('html_structure')


def validate_html(html):
    if not isinstance(html, str) or not html or len(html.encode('utf-8')) > MAX_BYTES:
        reject('size')
    plain = decoded(html)
    if '\x00' in html or SENSITIVE.search(plain):
        reject('sensitive_content')
    if ACTIVE_TEXT.search(plain):
        reject('active_content')
    if any(address.lower() not in ALLOWED_EMAILS for address in EMAIL.findall(plain)):
        reject('sensitive_content')
    parser = PreviewParser()
    try:
        parser.feed(html)
        parser.close()
    except PreviewRejected:
        raise
    except (ValueError, AssertionError, RecursionError):
        reject('html_structure')
    if parser.stack or not parser.doctype or any(parser.counts.get(tag) != 1 for tag in ('html', 'head', 'body')):
        reject('html_structure')
    text = ' '.join(unescape(' '.join(parser.text)).split())
    folded = text.casefold()
    if parser.images < 1 or not parser.counts.get('table') or 'petish' not in folded \
            or not re.search(r'\bP-1042\b', text) or not re.search(r'\bAlex\b', text) \
            or 'corner & gap cleaning brush' not in folded \
            or not re.search(r'(?<![0-9])[1][4][.,]60(?![0-9])', text):
        reject('synthetic_content')
    css = '\n'.join(parser.styles).casefold()
    if '#6532a1' not in css or '#ede7ff' not in css:
        reject('synthetic_content')


def read_html(path):
    try:
        info = path.lstat()
        if not stat.S_ISREG(info.st_mode) or info.st_nlink != 1 or info.st_size > MAX_BYTES:
            reject('filesystem')
        descriptor = os.open(path, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK)
        try:
            opened = os.fstat(descriptor)
            if not stat.S_ISREG(opened.st_mode) or opened.st_nlink != 1 \
                    or (opened.st_dev, opened.st_ino) != (info.st_dev, info.st_ino):
                reject('filesystem')
            with os.fdopen(descriptor, 'rb') as stream:
                descriptor = None
                content = stream.read(MAX_BYTES + 1)
        finally:
            if descriptor is not None:
                os.close(descriptor)
        if len(content) > MAX_BYTES:
            reject('size')
        return content.decode('utf-8', errors='strict')
    except PreviewRejected:
        raise
    except UnicodeDecodeError:
        reject('encoding')
    except (OSError, ValueError):
        reject('filesystem')


def validate_directory(directory):
    root = Path(os.path.abspath(directory))
    try:
        if root.resolve(strict=True) != root or not root.is_dir():
            reject('filesystem')
    except OSError:
        reject('filesystem')
    for filename in FILES:
        validate_html(read_html(root / filename))
    return {'status': 'success', 'files': list(FILES), 'checks': {
        'four_native_previews_validated': True, 'synthetic_content_verified': True,
        'approved_branding_verified': True, 'only_allowlisted_links': True,
        'only_local_logo': True, 'no_active_content': True, 'no_secret_markers': True,
    }}


def main(arguments):
    if len(arguments) != 1:
        report = {'status': 'failure', 'category': 'input'}
    else:
        try:
            report = validate_directory(arguments[0])
        except PreviewRejected as error:
            report = {'status': 'failure', 'category': error.category}
        except (OSError, ValueError, TypeError, RecursionError):
            report = {'status': 'failure', 'category': 'input'}
    print(json.dumps(report, separators=(',', ':')))
    return 0 if report['status'] == 'success' else 1


if __name__ == '__main__':
    raise SystemExit(main(sys.argv[1:]))
