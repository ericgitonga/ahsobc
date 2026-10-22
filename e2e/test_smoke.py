"""Golden-path smoke checks for the AHSOBC placeholder landing page and health endpoint."""

import json
import urllib.request
from _common import BASE_URL, browser_page


def test_index_placeholder_loads():
    with browser_page() as page:
        resp = page.goto("/")
        assert resp is not None and resp.status == 200
        assert "Alliance High School Old Boys Club" in page.title()
        assert page.get_by_role("heading", name="Hello, World!").is_visible()
        assert page.get_by_text("Strong to Serve").is_visible()
        assert page.get_by_text("Welcome to our website.").is_visible()
        gallery_link = page.get_by_role("link", name="Explore Centennial Photo Gallery")
        assert gallery_link.is_visible()
        assert gallery_link.get_attribute("href") == "/gallery/"


def test_health_endpoint_returns_ok():
    with urllib.request.urlopen(f"{BASE_URL}/api/health.php") as resp:
        assert resp.status == 200
        payload = json.loads(resp.read().decode("utf-8"))
        assert payload["status"] == "ok"
        assert payload["site"] == "Alliance High School Old Boys Club"
        assert payload["gallery_path"] == "/gallery/"


TESTS = [
    test_index_placeholder_loads,
    test_health_endpoint_returns_ok,
]

if __name__ == "__main__":
    for t in TESTS:
        t()
        print(f"PASS {t.__name__}")
