#!/usr/bin/env python3
"""Write README.md as a plain PDF, for the docs/ folder of the module zip
(PrestaShop Addons asks for the documentation as a PDF in the zip).

Usage: bin/readme-pdf.py README.md out.pdf

Standard library only: Helvetica text, one column, wrapped and paginated.
"""
import re
import sys
import textwrap

PAGE_W, PAGE_H, MARGIN = 595, 842, 56
REPLACE = {'→': '->', '…': '...', '—': '-', '–': '-', '‘': "'", '’': "'", '“': '"', '”': '"'}


def plain(line):
    for a, b in REPLACE.items():
        line = line.replace(a, b)
    line = re.sub(r'\[([^\]]+)\]\(([^)]+)\)', r'\1 (\2)', line)
    line = line.replace('**', '').replace('`', '')
    return line.encode('latin-1', 'replace').decode('latin-1')


def lines_of(markdown):
    out = []
    for raw in markdown.splitlines():
        line = plain(raw.rstrip())
        if line.startswith('#'):
            level = len(line) - len(line.lstrip('#'))
            out.append(('', 11))
            out.append((line.lstrip('# '), 18 if level == 1 else 14))
            continue
        if not line:
            out.append(('', 11))
            continue
        indent = '    ' if re.match(r'^(- |\d+\. )', line) else ''
        for i, part in enumerate(textwrap.wrap(line, 92) or ['']):
            out.append(((indent if i else '') + part, 11))
    return out


def esc(text):
    return text.replace('\\', '\\\\').replace('(', '\\(').replace(')', '\\)')


def main(src, dest):
    pages, current, y = [], [], PAGE_H - MARGIN
    for text, size in lines_of(open(src, encoding='utf-8').read()):
        step = size + 5
        if y - step < MARGIN:
            pages.append(current)
            current, y = [], PAGE_H - MARGIN
        y -= step
        if text:
            font = 'F2' if size > 11 else 'F1'
            current.append('BT /%s %d Tf %d %d Td (%s) Tj ET' % (font, size, MARGIN, y, esc(text)))
    pages.append(current)

    objects = ['<< /Type /Catalog /Pages 2 0 R >>', None,
               '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>',
               '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>']
    kids = []
    for ops in pages:
        stream = '\n'.join(ops).encode('latin-1')
        objects.append(b'<< /Length %d >>\nstream\n' % len(stream) + stream + b'\nendstream')
        objects.append('<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %d %d] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents %d 0 R >>'
                       % (PAGE_W, PAGE_H, len(objects)))
        kids.append('%d 0 R' % len(objects))
    objects[1] = '<< /Type /Pages /Kids [%s] /Count %d >>' % (' '.join(kids), len(kids))

    out = bytearray(b'%PDF-1.4\n')
    offsets = []
    for number, obj in enumerate(objects, 1):
        offsets.append(len(out))
        body = obj if isinstance(obj, bytes) else obj.encode('latin-1')
        out += b'%d 0 obj\n' % number + body + b'\nendobj\n'
    xref = len(out)
    out += b'xref\n0 %d\n0000000000 65535 f \n' % (len(objects) + 1)
    for offset in offsets:
        out += b'%010d 00000 n \n' % offset
    out += b'trailer\n<< /Size %d /Root 1 0 R >>\nstartxref\n%d\n%%%%EOF\n' % (len(objects) + 1, xref)
    open(dest, 'wb').write(bytes(out))


if __name__ == '__main__':
    main(sys.argv[1], sys.argv[2])
