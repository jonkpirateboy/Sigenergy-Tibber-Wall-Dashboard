#!/usr/bin/env python3
"""Set a Wiz light color from the dashboard electricity price cache."""

from __future__ import annotations

import argparse
import asyncio
import json
import socket
import sys
from datetime import datetime, timezone
from pathlib import Path
from typing import Any


PROJECT_ROOT = Path(__file__).resolve().parents[1]
DEFAULT_ENV_PATH = PROJECT_ROOT / "const.env"
DEFAULT_CACHE_PATH = PROJECT_ROOT / "var" / "electricity-prices.json"

DEFAULT_BREAKPOINTS = {
    "very_expensive": 3.0,
    "expensive": 2.0,
    "ok": 1.0,
    "cheap": 0.5,
}

STATUS_COLORS = {
    "very_expensive": (255, 0, 0),
    "expensive": (255, 50, 0),
    "ok": (255, 140, 0),
    "cheap": (255, 255, 224),
    "very_cheap": (255, 255, 255),
}


def load_env(path: Path) -> dict[str, str]:
    values: dict[str, str] = {}
    if not path.is_file():
        return values

    for raw_line in path.read_text(encoding="utf-8").splitlines():
        line = raw_line.strip()
        if not line or line.startswith("#") or "=" not in line:
            continue

        key, value = line.split("=", 1)
        value = value.strip()
        if (value.startswith('"') and value.endswith('"')) or (
            value.startswith("'") and value.endswith("'")
        ):
            value = value[1:-1]
        values[key.strip()] = value

    return values


def env_bool(env: dict[str, str], key: str, default: bool = False) -> bool:
    value = env.get(key)
    if value is None or value.strip() == "":
        return default

    return value.strip().lower() in {"1", "true", "yes", "on"}


def env_int(env: dict[str, str], key: str, default: int) -> int:
    try:
        return int(env.get(key, str(default)))
    except ValueError:
        return default


def env_float(env: dict[str, str], key: str, default: float) -> float:
    try:
        return float(env.get(key, str(default)))
    except ValueError:
        return default


def load_price_cache(path: Path) -> dict[str, Any]:
    if not path.is_file():
        raise RuntimeError(f"Price cache does not exist: {path}")

    try:
        data = json.loads(path.read_text(encoding="utf-8"))
    except json.JSONDecodeError as error:
        raise RuntimeError(f"Price cache is invalid JSON: {error}") from error

    if not isinstance(data, dict):
        raise RuntimeError("Price cache root must be an object.")

    return data


def parse_timestamp(value: Any) -> datetime | None:
    if not isinstance(value, str) or value == "":
        return None

    try:
        normalized = value.replace("Z", "+00:00")
        parsed = datetime.fromisoformat(normalized)
    except ValueError:
        return None

    return parsed if parsed.tzinfo else parsed.replace(tzinfo=timezone.utc)


def assert_cache_fresh(data: dict[str, Any], max_age_minutes: int) -> None:
    if max_age_minutes <= 0:
        return

    updated_at = parse_timestamp(data.get("updatedAt"))
    if updated_at is None:
        raise RuntimeError("Price cache has no valid updatedAt timestamp.")

    age_seconds = (datetime.now(timezone.utc) - updated_at.astimezone(timezone.utc)).total_seconds()
    if age_seconds > max_age_minutes * 60:
        age_minutes = round(age_seconds / 60)
        raise RuntimeError(
            f"Price cache is too old: {age_minutes} minutes "
            f"(max {max_age_minutes})."
        )


def current_price(data: dict[str, Any]) -> float:
    current = data.get("current")
    if not isinstance(current, dict):
        raise RuntimeError("Price cache has no current price.")

    total = current.get("total")
    if not isinstance(total, (int, float)):
        raise RuntimeError("Current price has no numeric total.")

    return float(total)


def price_breakpoints(data: dict[str, Any], env: dict[str, str]) -> dict[str, float]:
    values = DEFAULT_BREAKPOINTS.copy()
    cached = data.get("breakpoints")
    if isinstance(cached, dict):
        for key in values:
            if isinstance(cached.get(key), (int, float)):
                values[key] = float(cached[key])

    env_keys = {
        "very_expensive": "PRICE_BREAKPOINT_VERY_EXPENSIVE",
        "expensive": "PRICE_BREAKPOINT_EXPENSIVE",
        "ok": "PRICE_BREAKPOINT_OK",
        "cheap": "PRICE_BREAKPOINT_CHEAP",
    }
    for key, env_key in env_keys.items():
        values[key] = env_float(env, env_key, values[key])

    return values


def classify_price(price: float, breakpoints: dict[str, float]) -> str:
    if price > breakpoints["very_expensive"]:
        return "very_expensive"
    if price > breakpoints["expensive"]:
        return "expensive"
    if price > breakpoints["ok"]:
        return "ok"
    if price > breakpoints["cheap"]:
        return "cheap"

    return "very_cheap"


def resolve_wiz_host(env: dict[str, str]) -> str:
    hostname = env.get("WIZ_HOSTNAME", "").strip()
    fallback_ip = env.get("WIZ_FALLBACK_IP", "").strip()

    if hostname:
        try:
            return socket.gethostbyname(hostname)
        except socket.gaierror:
            if not fallback_ip:
                raise RuntimeError(f"Could not resolve WIZ_HOSTNAME: {hostname}")

    if fallback_ip:
        return fallback_ip

    raise RuntimeError("Set WIZ_HOSTNAME or WIZ_FALLBACK_IP in const.env.")


async def set_wiz_color(ip: str, rgb: tuple[int, int, int], brightness: int) -> None:
    try:
        from pywizlight import PilotBuilder, wizlight
    except ImportError as error:
        raise RuntimeError("Install pywizlight first: python3 -m pip install pywizlight") from error

    light = wizlight(ip)
    brightness = max(10, min(100, brightness))

    try:
        pilot = PilotBuilder(rgb=rgb, brightness=brightness)
    except TypeError:
        pilot = PilotBuilder(rgb=rgb)

    await light.turn_on(pilot)


async def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--env", type=Path, default=DEFAULT_ENV_PATH)
    parser.add_argument("--cache", type=Path, default=DEFAULT_CACHE_PATH)
    parser.add_argument("--dry-run", action="store_true")
    args = parser.parse_args()

    env = load_env(args.env)
    if not env_bool(env, "WIZ_ENABLED", False):
        print("Wiz disabled. Set WIZ_ENABLED=1 in const.env.")
        return 0

    data = load_price_cache(args.cache)
    assert_cache_fresh(data, env_int(env, "WIZ_MAX_PRICE_AGE_MINUTES", 180))

    value = current_price(data)
    status = classify_price(value, price_breakpoints(data, env))
    color = STATUS_COLORS[status]
    brightness = env_int(env, "WIZ_BRIGHTNESS", 80)
    ip = resolve_wiz_host(env)

    print(
        f"price={value:.4f} status={status} rgb={color[0]},{color[1]},{color[2]} "
        f"brightness={brightness} ip={ip}"
    )

    if args.dry_run:
        return 0

    await set_wiz_color(ip, color, brightness)
    return 0


if __name__ == "__main__":
    try:
        raise SystemExit(asyncio.run(main()))
    except Exception as error:
        print(f"Error: {error}", file=sys.stderr)
        raise SystemExit(1)
