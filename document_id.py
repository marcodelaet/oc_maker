"""Geração de ID único no formato AAAAMM-XXXX."""

from __future__ import annotations

import datetime as dt
import re
import secrets
import string
import unicodedata


ALPHANUM = string.ascii_letters + string.digits

_INVALID_FILENAME = re.compile(r'[\\/:*?"<>|]')


def generate_document_id(when: dt.date | None = None) -> str:
    when = when or dt.date.today()
    suffix = "".join(secrets.choice(ALPHANUM) for _ in range(4))
    return f"{when.year:04d}{when.month:02d}-{suffix}"


def pdf_timestamp(when: dt.datetime | None = None) -> str:
    when = when or dt.datetime.now()
    return when.strftime("%Y%m%d_%H%M%S")


def _remove_accents(text: str) -> str:
    normalized = unicodedata.normalize("NFKD", text)
    return "".join(c for c in normalized if not unicodedata.combining(c))


def _sanitize_filename_part(text: str, default: str) -> str:
    name = (text or default).strip()
    name = _remove_accents(name)
    return _INVALID_FILENAME.sub("_", name)


def build_pdf_filename(
    campanha: str,
    anunciante: str = "",
    when: dt.datetime | None = None,
) -> str:
    camp = _sanitize_filename_part(campanha, "campanha")
    adv = _sanitize_filename_part(anunciante, "anunciante")
    return f"{camp} - {adv} - {pdf_timestamp(when)}.pdf"
