"""Records the README demo frames: the UI over the docs fixture app, from the
POST /orders route to the OrderPlaced chain graph. `just demo-gif` runs it in
the Playwright image against `tools/demo-server.php`, then joins the frames.

Usage: python record.py BASE_URL OUT_DIR
"""

import sys
import time
import urllib.request
from pathlib import Path

from playwright.sync_api import sync_playwright

BASE, OUT = sys.argv[1].rstrip("/"), Path(sys.argv[2])
EVENT = "App.Events.OrderPlaced"
ROOT = "App\\Events\\OrderPlaced"
LISTENER = "App\\Listeners\\SendReceipt::handle"
JOB = "App\\Jobs\\SendMail"
frames = []


def wait_for_server():
    """The server container starts alongside this one; give it a few seconds."""
    for _ in range(50):
        try:
            urllib.request.urlopen(f"{BASE}/loom", timeout=2)
            return
        except OSError:
            time.sleep(0.2)
    sys.exit(f"No UI at {BASE}/loom")


def shot(page, seconds):
    path = OUT / f"{len(frames):02d}.png"
    page.screenshot(path=str(path))
    frames.append((path.name, seconds))


def click_node(page, key):
    """Clicks a chain node where the canvas draws it."""
    pos = page.evaluate(
        """key => {
            const cy = Alpine.$data(document.querySelector('[x-data=loomChain]')).cy;
            const box = cy.container().getBoundingClientRect();
            const at = cy.getElementById(key).renderedPosition();
            return {x: box.x + at.x, y: box.y + at.y};
        }""",
        key,
    )
    page.mouse.click(pos["x"], pos["y"])
    settle(page)


def settle(page):
    """Waits for the Livewire round trip and the graph layout."""
    page.wait_for_load_state("networkidle")
    page.wait_for_timeout(800)


wait_for_server()

with sync_playwright() as p:
    browser = p.chromium.launch()
    page = browser.new_page(viewport={"width": 1920, "height": 1080}, color_scheme="light")

    page.goto(f"{BASE}/loom/routes")
    shot(page, 2.5)

    page.goto(f"{BASE}/loom/events/{EVENT}")
    shot(page, 2.5)

    # Depth 1 first: fewer columns, so the first graph frame stays legible.
    page.goto(f"{BASE}/loom/chain/{EVENT}?depth=1")
    settle(page)
    shot(page, 2.5)

    click_node(page, f"{ROOT}>{LISTENER}")
    shot(page, 2.5)

    click_node(page, f"{ROOT}>{LISTENER}>{JOB}")
    shot(page, 2.5)

    page.get_by_role("button", name="3", exact=True).click()
    settle(page)
    shot(page, 4)

    browser.close()

lines = [f"file '{name}'\nduration {seconds}" for name, seconds in frames]
# The concat demuxer ignores the last duration unless the last file repeats.
lines.append(f"file '{frames[-1][0]}'")
(OUT / "frames.txt").write_text("\n".join(lines) + "\n")
