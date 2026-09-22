"""Gera logo_converta.svg a partir do PNG (aparência idêntica via embed)."""
from __future__ import annotations

import base64
import struct
from pathlib import Path

ROOT = Path(__file__).resolve().parent
PNG = ROOT / "logo_converta.png"

TARGETS = [
    ROOT / "logo_converta.svg",
    ROOT / "web-js" / "assets" / "logo_converta.svg",
    ROOT / "web-node" / "public" / "assets" / "logo_converta.svg",
    ROOT / "web-php" / "public" / "assets" / "logo_converta.svg",
]


def main() -> None:
    data = PNG.read_bytes()
    width, height = struct.unpack(">II", data[16:24])
    b64 = base64.b64encode(data).decode("ascii")
    svg = (
        f'<svg xmlns="http://www.w3.org/2000/svg" '
        f'xmlns:xlink="http://www.w3.org/1999/xlink" '
        f'width="{width}" height="{height}" viewBox="0 0 {width} {height}" '
        f'role="img" aria-label="Converta Ads by Retail Media">\n'
        f'  <title>Converta Ads by Retail Media</title>\n'
        f'  <image width="{width}" height="{height}" preserveAspectRatio="xMidYMid meet" '
        f'xlink:href="data:image/png;base64,{b64}"/>\n'
        f"</svg>\n"
    )
    for path in TARGETS:
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_text(svg, encoding="utf-8")
        print(f"OK {path} ({width}x{height})")


if __name__ == "__main__":
    main()
