import http from "http";
import type { AddressInfo } from "net";
import type { BrowserContext } from "@playwright/test";
import { dbQuery, type Params, type Session } from "./tenants";

/**
 * A stand-in for S3, so the upload endpoints can be tested without a bucket. The app reaches it at
 * AWS_S3_SERVER_ENDPOINT (a local HTTP server); browsers PUT to AWS_S3_BROWSER_ENDPOINT, an amazonaws.com
 * address the page's CSP allows, which `routeBrowser` sends to the same store. Signatures aren't checked.
 */
export const BUCKET = "e2e-bucket";
export const BROWSER_ENDPOINT = "https://s3.e2e.amazonaws.com";
const CONFIG_KEYS = ["FILES_ENABLED", "AWS_S3_KEY", "AWS_S3_SECRET", "AWS_S3_BUCKET", "AWS_S3_REGION", "AWS_S3_BROWSER_ENDPOINT", "AWS_S3_SERVER_ENDPOINT", "AWS_S3_ENDPOINT_PATHSTYLE"];

export type FakeS3 = {
  /** Object keys (without the bucket) and their contents */
  objects: Map<string, Buffer>;
  routeBrowser(context: BrowserContext): Promise<void>;
  stop(): Promise<void>;
};

function keyOf(pathname: string) {
  const prefix = `/${BUCKET}/`;
  return pathname.startsWith(prefix) ? decodeURIComponent(pathname.slice(prefix.length)) : null;
}

/** Starts the fake bucket and points the app's file storage settings at it. `stop()` turns file storage off again. */
export async function startFakeS3(): Promise<FakeS3> {
  const objects = new Map<string, Buffer>();
  const server = http.createServer((req, res) => {
    const key = keyOf(new URL(req.url!, "http://localhost").pathname);
    const chunks: Buffer[] = [];
    req.on("data", (chunk) => chunks.push(chunk));
    req.on("end", () => {
      if (key === null) return res.writeHead(404).end();
      if (req.method === "PUT") {
        objects.set(key, Buffer.concat(chunks));
        return res.writeHead(200, { etag: '"e2e"' }).end();
      }
      if (req.method === "DELETE") {
        objects.delete(key);
        return res.writeHead(204).end();
      }
      const body = objects.get(key);
      if (!body) return res.writeHead(404, { "content-type": "application/xml" }).end(req.method === "HEAD" ? undefined : "<Error><Code>NoSuchKey</Code></Error>");
      res.writeHead(200, { "content-length": body.length, "content-type": "application/octet-stream", etag: '"e2e"' });
      res.end(req.method === "HEAD" ? undefined : body);
    });
  });
  await new Promise<void>((resolve) => server.listen(0, "127.0.0.1", resolve));
  const port = (server.address() as AddressInfo).port;

  const settings: Record<string, string> = {
    FILES_ENABLED: "Enabled", AWS_S3_KEY: "e2e-key", AWS_S3_SECRET: "e2e-secret", AWS_S3_BUCKET: BUCKET, AWS_S3_REGION: "us-east-1",
    AWS_S3_BROWSER_ENDPOINT: BROWSER_ENDPOINT, AWS_S3_SERVER_ENDPOINT: `http://127.0.0.1:${port}`, AWS_S3_ENDPOINT_PATHSTYLE: "Enabled",
  };
  for (const [key, value] of Object.entries(settings)) dbQuery("REPLACE INTO config (config_key, config_value) VALUES (?, ?)", [key, value]);

  return {
    objects,
    async routeBrowser(context) {
      await context.route(`${BROWSER_ENDPOINT}/**`, async (route) => {
        const request = route.request();
        const cors = { "access-control-allow-origin": "*", "access-control-allow-methods": "PUT, POST", "access-control-allow-headers": "*", "access-control-expose-headers": "etag" };
        if (request.method() === "OPTIONS") return route.fulfill({ status: 200, headers: cors });
        const key = keyOf(new URL(request.url()).pathname);
        if (request.method() !== "PUT" || key === null) return route.fulfill({ status: 404, headers: cors });
        objects.set(key, request.postDataBuffer() ?? Buffer.alloc(0));
        return route.fulfill({ status: 200, headers: { ...cors, etag: '"e2e"' } });
      });
    },
    async stop() {
      dbQuery(`DELETE FROM config WHERE config_key IN (${CONFIG_KEYS.map(() => "?").join(", ")})`, CONFIG_KEYS);
      await new Promise((resolve) => server.close(resolve));
    },
  };
}

/** Uploads a file the way uppy.twig does: asks for a signed URL, stores the file, then registers it. Returns uploadSuccess.php's result. */
export async function uploadFile(session: Session, s3: FakeS3, params: Params & { typeid: number | string }, contents = Buffer.from("e2e upload")) {
  const signature = await session.api("/api/s3files/generateSignatureUppy.php", { filename: "uploads/E2E-FILE/whatever.pdf", contentType: "application/pdf" });
  const key: string = signature.json.key;
  s3.objects.set(key, contents);
  return session.api("/api/s3files/uploadSuccess.php", { name: key, size: contents.length, subtype: "", originalName: "Uploaded by e2e.pdf", public: 0, ...params });
}
