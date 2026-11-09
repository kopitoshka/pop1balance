#!/usr/bin/env python3
# Resolve Telegram @usernames via MTProto (Telethon) using the BOT token.
# One-shot: connect → resolve → disconnect. No daemon.
#
# Usage: resolve_username.py <username> [username ...]
# Env / .env: TG_API_ID, TG_API_HASH, TELEGRAM_BOT_TOKEN
# Optional: P1BOT_ROOT (project root), P1BOT_SESSION_DIR
#
# stdout: one JSON object per username
# exit: 0 all ok | 1 not_found/not_user | 2 config | 3 flood_wait | 4 other

from __future__ import annotations

import asyncio
import json
import os
import re
import stat
import sys
from pathlib import Path


def project_root() -> Path:
	env = os.environ.get("P1BOT_ROOT", "").strip()
	if env:
		return Path(env)
	return Path(__file__).resolve().parent.parent


def load_dotenv(path: Path) -> None:
	"""Minimal KEY=VALUE loader; does not override existing environment."""
	if not path.is_file():
		return
	try:
		text = path.read_text(encoding="utf-8")
	except OSError:
		return
	for raw in text.splitlines():
		line = raw.strip()
		if not line or line.startswith("#") or "=" not in line:
			continue
		key, _, value = line.partition("=")
		key = key.strip()
		if not key or key in os.environ:
			continue
		value = value.strip()
		if len(value) >= 2 and value[0] == value[-1] and value[0] in "\"'":
			value = value[1:-1]
		os.environ[key] = value


def normalize_username(raw: str) -> str:
	return raw.strip().lstrip("@")


def emit(obj: dict) -> None:
	sys.stdout.write(json.dumps(obj, ensure_ascii=False) + "\n")
	sys.stdout.flush()


def chmod_session(path: Path) -> None:
	for candidate in (path, Path(str(path) + ".session"), path.with_suffix(".session")):
		if candidate.is_file():
			try:
				os.chmod(candidate, stat.S_IRUSR | stat.S_IWUSR)
			except OSError:
				pass


async def resolve_one(client, username: str) -> dict:
	from telethon.errors import FloodWaitError, UsernameInvalidError, UsernameNotOccupiedError
	from telethon.tl.functions.contacts import ResolveUsernameRequest
	from telethon.tl.types import PeerUser, User

	base = {"username": username}
	try:
		result = await client(ResolveUsernameRequest(username))
	except UsernameNotOccupiedError:
		return {**base, "ok": False, "error": "not_found"}
	except UsernameInvalidError:
		return {**base, "ok": False, "error": "not_found"}
	except FloodWaitError as e:
		return {**base, "ok": False, "error": "flood_wait", "retry_after": int(getattr(e, "seconds", 0) or 0)}
	except Exception as e:  # noqa: BLE001 — surface to PHP as error string
		name = type(e).__name__
		msg = str(e)
		if "flood" in name.lower() or "flood" in msg.lower():
			retry = getattr(e, "seconds", None) or getattr(e, "value", None) or 0
			return {**base, "ok": False, "error": "flood_wait", "retry_after": int(retry)}
		return {**base, "ok": False, "error": "rpc", "detail": f"{name}: {msg}"[:200]}

	user = None
	users_by_id = {u.id: u for u in (result.users or []) if isinstance(u, User)}
	peer = result.peer
	if isinstance(peer, PeerUser) and peer.user_id in users_by_id:
		user = users_by_id[peer.user_id]
	elif users_by_id:
		# Prefer exact username match among returned users.
		wanted = username.lower()
		for u in users_by_id.values():
			if (u.username or "").lower() == wanted:
				user = u
				break
		if user is None:
			user = next(iter(users_by_id.values()))

	if user is None:
		return {**base, "ok": False, "error": "not_user"}
	if getattr(user, "bot", False):
		return {**base, "ok": False, "error": "not_user"}
	if getattr(user, "deleted", False):
		return {**base, "ok": False, "error": "not_found"}

	return {
		**base,
		"ok": True,
		"id": int(user.id),
		"first_name": user.first_name or "",
		"last_name": user.last_name or "",
		"username_actual": user.username or username,
	}


async def amain(usernames: list[str]) -> int:
	root = project_root()
	load_dotenv(root / ".env")

	api_id_raw = os.environ.get("TG_API_ID", "").strip()
	api_hash = os.environ.get("TG_API_HASH", "").strip()
	token = os.environ.get("TELEGRAM_BOT_TOKEN", "").strip()
	if not api_id_raw or not api_hash or not token:
		emit({"username": usernames[0] if usernames else "", "ok": False, "error": "config",
			"detail": "Need TG_API_ID, TG_API_HASH, TELEGRAM_BOT_TOKEN"})
		return 2
	try:
		api_id = int(api_id_raw)
	except ValueError:
		emit({"username": "", "ok": False, "error": "config", "detail": "TG_API_ID must be int"})
		return 2

	session_dir = Path(os.environ.get("P1BOT_SESSION_DIR") or (root / "storage" / "runtime"))
	session_dir.mkdir(parents=True, exist_ok=True)
	try:
		os.chmod(session_dir, 0o700)
	except OSError:
		pass
	session_path = session_dir / "telethon_bot"

	try:
		from telethon import TelegramClient
	except ImportError:
		emit({"username": "", "ok": False, "error": "config",
			"detail": "Telethon not installed (pip install -r bin/requirements.txt)"})
		return 2

	client = TelegramClient(str(session_path), api_id, api_hash)
	exit_code = 0
	try:
		await client.start(bot_token=token)
		chmod_session(session_path)
		for raw in usernames:
			username = normalize_username(raw)
			if not username or not re.match(r"^[A-Za-z][A-Za-z0-9_]{2,31}$", username):
				emit({"username": username or raw, "ok": False, "error": "not_found"})
				exit_code = max(exit_code, 1)
				continue
			row = await resolve_one(client, username)
			emit(row)
			if row.get("ok"):
				continue
			err = row.get("error")
			if err == "flood_wait":
				exit_code = max(exit_code, 3)
			elif err in ("not_found", "not_user"):
				exit_code = max(exit_code, 1)
			elif err == "config":
				exit_code = max(exit_code, 2)
			else:
				exit_code = max(exit_code, 4)
	except Exception as e:  # noqa: BLE001
		emit({"username": "", "ok": False, "error": "rpc", "detail": f"{type(e).__name__}: {e}"[:200]})
		exit_code = max(exit_code, 4)
	finally:
		await client.disconnect()
		chmod_session(session_path)

	return exit_code


def main() -> int:
	args = [a for a in sys.argv[1:] if a.strip()]
	if not args:
		sys.stderr.write("Usage: resolve_username.py <username> [username ...]\n")
		return 2
	return asyncio.run(amain(args))


if __name__ == "__main__":
	sys.exit(main())
