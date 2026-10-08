"""Observe raw HTTP HEAD bytes without redirecting or normalising fixtures."""

from http.client import parse_headers
from io import BytesIO
import socket
from urllib.parse import urlsplit


def raw_head(url, headers=None, timeout=20):
    """Return wire status, fields and body; retain even an illegal HEAD body.

    GET wrappers retain their own redirect and byte-normalisation policies.
    This helper deliberately applies neither policy to the raw response.
    """
    address = urlsplit(url)
    fields = {"Host": address.netloc, "Connection": "close", **(headers or {})}
    target = address.path or "/"
    if address.query:
        target += "?" + address.query
    outgoing = f"HEAD {target} HTTP/1.1\r\n"
    outgoing += "".join(f"{name}: {value}\r\n" for name, value in fields.items()) + "\r\n"
    with socket.create_connection((address.hostname, address.port), timeout=timeout) as connection:
        connection.sendall(outgoing.encode("ascii"))
        chunks = []
        while chunk := connection.recv(65536):
            chunks.append(chunk)
    block, separator, body = b"".join(chunks).partition(b"\r\n\r\n")
    assert separator, block
    line, fields = block.split(b"\r\n", 1)
    return int(line.split()[1]), parse_headers(BytesIO(fields)), body
