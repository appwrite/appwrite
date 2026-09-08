import importlib.util
import io
from pathlib import Path
import sys
import tempfile
import unittest
from unittest.mock import patch

spec = importlib.util.spec_from_file_location(
    "publish_assets", Path(__file__).with_name("publish-assets.py")
)
publisher = importlib.util.module_from_spec(spec)
sys.modules[spec.name] = publisher
spec.loader.exec_module(publisher)


class Missing(Exception):
    response = {"Error": {"Code": "404"}}


class FakeS3:
    def __init__(self):
        self.objects = {}
        self.writes = []

    def head_object(self, *, Bucket, Key):
        if Key not in self.objects:
            raise Missing()
        return self.objects[Key]

    def put_object(self, *, Bucket, Key, Body, ContentType, CacheControl, Metadata):
        data = Body.read()
        self.writes.append(Key)
        self.objects[Key] = {
            "ContentLength": len(data),
            "ContentType": ContentType,
            "CacheControl": CacheControl,
            "Metadata": Metadata,
        }


class PublishingTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        self.clients = [self.root / "amd64", self.root / "arm64"]
        self.public = self.root / "public"
        self.public.mkdir()
        for client in self.clients:
            self.write(client, "assets/index-abcdefgh.js", b"export default 1")

    def write(self, root, key, data):
        path = root / key
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_bytes(data)
        return path

    def assets(self):
        return publisher.collect_assets(self.clients, self.public)

    def test_merges_identical_keys_and_keeps_architecture_specific_chunks(self):
        self.write(self.clients[1], "assets/lazy-ijklmnop.js", b"export default 2")
        self.assertEqual(len(self.assets()), 2)

    def test_conflicting_architectures_fail_before_upload(self):
        self.write(self.clients[1], "assets/index-abcdefgh.js", b"different bytes")
        with self.assertRaisesRegex(ValueError, "Architecture builds disagree"):
            self.assets()

    def test_html_exports_maps_and_server_files_are_excluded(self):
        for key in [
            "index.html",
            "docs/a.html",
            "robots.txt",
            "llms.txt",
            "assets/a.js.map",
            "assets/a.html",
            "server/server.js",
            "images/a.txt",
            ".env",
        ]:
            self.write(self.clients[0], key, b"must stay on origin")
        self.assertEqual([a.key for a in self.assets()], ["assets/index-abcdefgh.js"])

    def test_public_files_with_hash_like_names_are_mutable(self):
        self.write(self.public, "assets/index-abcdefgh.js", b"source public asset")
        self.assertEqual(self.assets()[0].cache_control, publisher.MUTABLE)

    def test_images_fonts_and_wasm_have_correct_policy_and_type(self):
        self.write(self.clients[0], "fonts/test.woff2", b"font")
        self.write(self.clients[0], "images/test.avif", b"image")
        self.write(self.clients[0], "assets/cli-abcdefgh.wasm", b"wasm")
        assets = {a.key: a for a in self.assets()}
        self.assertEqual(assets["fonts/test.woff2"].content_type, "font/woff2")
        self.assertEqual(assets["images/test.avif"].cache_control, publisher.MUTABLE)
        self.assertEqual(
            assets["assets/cli-abcdefgh.wasm"].content_type, "application/wasm"
        )
        self.assertEqual(
            assets["assets/cli-abcdefgh.wasm"].cache_control, publisher.IMMUTABLE
        )

    def test_missing_or_empty_client_is_rejected(self):
        with self.assertRaisesRegex(ValueError, "Missing client"):
            publisher.collect_assets([self.root / "missing"], self.public)
        empty = self.root / "empty"
        empty.mkdir()
        with self.assertRaisesRegex(ValueError, "no bundled assets"):
            publisher.collect_assets([empty], self.public)

    def test_zero_byte_and_symlink_assets_are_rejected(self):
        path = self.write(self.clients[0], "assets/empty-abcdefgh.js", b"")
        with self.assertRaisesRegex(ValueError, "Empty asset"):
            self.assets()
        path.unlink()
        path.symlink_to(self.clients[1] / "assets/index-abcdefgh.js")
        with self.assertRaisesRegex(ValueError, "symlink"):
            self.assets()

    def test_publish_is_idempotent_and_retains_old_chunks(self):
        s3 = FakeS3()
        s3.objects["assets/old-abcdefgh.js"] = {"Metadata": {"sha256": "old"}}
        self.assertEqual(publisher.publish(s3, "bucket", self.assets()), 1)
        self.assertEqual(publisher.publish(s3, "bucket", self.assets()), 0)
        self.assertIn("assets/old-abcdefgh.js", s3.objects)

    def test_immutable_remote_collision_prevents_even_mutable_writes(self):
        s3 = FakeS3()
        self.write(self.clients[0], "images/image.png", b"mutable")
        s3.objects["assets/index-abcdefgh.js"] = {"Metadata": {"sha256": "old"}}
        with self.assertRaisesRegex(ValueError, "Immutable key"):
            publisher.publish(s3, "bucket", self.assets())
        self.assertEqual(s3.writes, [])

    def test_mutable_file_can_be_updated(self):
        s3 = FakeS3()
        self.write(self.clients[0], "images/image.png", b"first")
        publisher.publish(s3, "bucket", self.assets())
        self.write(self.clients[0], "images/image.png", b"second")
        self.assertEqual(publisher.publish(s3, "bucket", self.assets()), 1)

    def test_authentication_error_is_not_treated_as_missing(self):
        class Forbidden(Exception):
            response = {"Error": {"Code": "403"}}

        s3 = FakeS3()
        with patch.object(s3, "head_object", side_effect=Forbidden()):
            with self.assertRaises(Forbidden):
                publisher.publish(s3, "bucket", self.assets())
        self.assertEqual(s3.writes, [])

    def test_failed_remote_verification_fails_publication(self):
        s3 = FakeS3()
        with patch.object(s3, "put_object"):
            with self.assertRaisesRegex(ValueError, "metadata verification"):
                publisher.publish(s3, "bucket", self.assets())

    def test_http_verifies_bytes_mime_and_cache_control(self):
        from email.message import Message

        asset = self.assets()[0]

        class Response(io.BytesIO):
            status = 200
            headers = Message()

        Response.headers["Content-Type"] = asset.content_type
        Response.headers["Cache-Control"] = asset.cache_control
        with patch.object(
            publisher, "urlopen", return_value=Response(asset.path.read_bytes())
        ):
            self.assertEqual(publisher.verify_http("https://cdn.example", [asset]), 1)
        with patch.object(
            publisher, "urlopen", side_effect=lambda *a, **k: Response(b"wrong")
        ), patch.object(publisher.time, "sleep"):
            with self.assertRaisesRegex(ValueError, "CDN bytes differ"):
                publisher.verify_http("https://cdn.example", [asset])


if __name__ == "__main__":
    unittest.main()
