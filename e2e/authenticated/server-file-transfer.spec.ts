import { createHmac } from "crypto";
import { cacheCdn } from "../cdn";
import { BASE_URL, FAKE_S3_URL } from "../env";
import { login } from "../fixtures";
import { dbQuery, expect, newSession, seedTenants, Session, succeeded, test } from "../tenants";

/**
 * FILES_TRANSFER_MODE = Server: every upload and download goes through AdamRMS, which talks to the bucket using
 * the server endpoint, so browsers never learn where the bucket is (and it needn't be reachable from them). This is
 * what lets a bucket that can only be reached through its API endpoint, such as Cloudflare R2 behind a custom domain,
 * be used without giving browsers that endpoint. The bucket is e2e/setup/fakeS3.php, which Playwright starts.
 */

const BUCKET = "e2e-bucket";
/** Set so a test can check it never reaches the browser */
const BROWSER_ENDPOINT = "https://browser-endpoint.invalid";
const CONFIG: Record<string, string> = {
  FILES_ENABLED: "Enabled",
  FILES_TRANSFER_MODE: "Server",
  AWS_S3_KEY: "e2e-key",
  AWS_S3_SECRET: "e2e-secret",
  AWS_S3_BUCKET: BUCKET,
  AWS_S3_REGION: "us-east-1",
  AWS_S3_BROWSER_ENDPOINT: BROWSER_ENDPOINT,
  AWS_S3_SERVER_ENDPOINT: FAKE_S3_URL,
  AWS_S3_ENDPOINT_PATHSTYLE: "Enabled",
};
/** The JWT key setup/seed.php sets */
const JWT_KEY = "e2eTestJwtKey000".repeat(4);
function sign(fileId: number, download: boolean, expires: number) {
  const key = createHmac("sha256", JWT_KEY).update("adamrms-file-stream-v1").digest();
  return createHmac("sha256", key).update(`${fileId}|${download ? 1 : 0}|${expires}`).digest("hex");
}
const PNG = Buffer.from("iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==", "base64");

test.beforeEach(() => {
  for (const [key, value] of Object.entries(CONFIG)) dbQuery("REPLACE INTO config (config_key, config_value) VALUES (?, ?)", [key, value]);
});
test.afterEach(() => {
  dbQuery(`DELETE FROM config WHERE config_key IN (${Object.keys(CONFIG).map(() => "?").join(",")})`, Object.keys(CONFIG));
  dbQuery("DELETE FROM s3files WHERE s3files_original_name LIKE 'e2e-server-%'");
  seedTenants();
});

type Fields = Record<string, string | number>;
function upload(session: Session, fields: Fields, file: { name: string; mimeType?: string; buffer: Buffer }) {
  const multipart: Record<string, any> = { file: { mimeType: "application/octet-stream", ...file } };
  for (const [key, value] of Object.entries(fields)) multipart[key] = String(value);
  return session.upload("/api/s3files/upload.php", multipart);
}
const assetTypeFile = (assetTypeId: number): Fields => ({ typeid: 3, subtype: assetTypeId, public: 0, type: "ASSET-TYPE-FILE" });
type Row = { s3files_path: string; s3files_filename: string; s3files_extension: string; s3files_meta_size: number; s3files_name: string; instances_id: number };
const row = (id: number) => dbQuery<Row>("SELECT s3files_path, s3files_filename, s3files_extension, s3files_meta_size, s3files_name, instances_id FROM s3files WHERE s3files_id = ?", [id])[0];
const objectKey = (file: Row) => `${file.s3files_path}/${file.s3files_filename}.${file.s3files_extension}`;
async function link(session: Session, id: number, params: Fields = {}) {
  const response = await session.api("/api/file/index.php", { f: id, ...params });
  expect(succeeded(response), response.body.slice(0, 300)).toBe(true);
  return response.json.response.url as string;
}

test("a file is uploaded through the server, which names it and records its real size", async ({ asA, tenants: { a } }) => {
  const response = await upload(asA, { ...assetTypeFile(a.assetTypeId), name: "uploads/chosen-by-the-browser.png", size: 1 }, { name: "e2e-server-pixel.png", mimeType: "image/png", buffer: PNG });
  expect(succeeded(response), response.body.slice(0, 300)).toBe(true);
  expect(response.json.response).toMatchObject({ resize: false, url: `${BASE_URL}/api/file/?f=${response.json.response.id}` });
  const file = row(response.json.response.id);
  expect(file).toMatchObject({ s3files_path: "uploads/ASSET-TYPE-FILE", s3files_extension: "png", s3files_meta_size: PNG.length, s3files_name: "e2e-server-pixel", instances_id: a.instanceId });
  expect(file.s3files_filename).toMatch(/^\d{13}-\d+$/);
  // It's in the bucket, under the name the server gave it, with a type the server chose
  const stored = await fetch(`${FAKE_S3_URL}/${BUCKET}/${objectKey(file)}`);
  expect(stored.status).toBe(200);
  expect(stored.headers.get("content-type")).toBe("image/png");
  expect(Buffer.from(await stored.arrayBuffer()).equals(PNG)).toBe(true);
});

test("a file is downloaded through a signed link to the server, which needs no session", async ({ playwright, asA, tenants: { a } }) => {
  const id = (await upload(asA, assetTypeFile(a.assetTypeId), { name: "e2e-server-pixel.png", buffer: PNG })).json.response.id;
  const url = await link(asA, id);
  expect(url.startsWith(`${BASE_URL}/api/file/stream.php?`)).toBe(true);
  expect(url).not.toContain(BUCKET);
  expect(url).not.toContain(FAKE_S3_URL);
  expect(url).not.toContain("X-Amz");

  const anonymous = await playwright.request.newContext();
  try {
    const response = await anonymous.get(url);
    expect(response.status()).toBe(200);
    expect((await response.body()).equals(PNG)).toBe(true);
    const headers = response.headers();
    expect(headers["content-type"]).toBe("image/png");
    expect(headers["content-disposition"]).toBe('inline; filename="e2e-server-pixel.png"');
    expect(headers["x-content-type-options"]).toBe("nosniff");
    expect(headers["content-security-policy"]).toContain("sandbox");
    expect(headers["cache-control"]).toMatch(/^private, max-age=\d+, immutable$/);

    // A second look, with the copy the browser kept, isn't sent again
    expect((await anonymous.get(url, { headers: { "If-None-Match": headers["etag"] } })).status()).toBe(304);
    // Part of it, as a video player asks for
    const part = await anonymous.get(url, { headers: { Range: "bytes=0-3" } });
    expect(part.status()).toBe(206);
    expect(part.headers()["content-range"]).toBe(`bytes 0-3/${PNG.length}`);
    expect((await part.body()).equals(PNG.subarray(0, 4))).toBe(true);
    // HEAD, which sends the headers only
    const head = await anonymous.head(url);
    expect(head.status()).toBe(200);
    expect(head.headers()["content-length"]).toBe(String(PNG.length));

    // A forced download is a different link, which says so
    const download = await anonymous.get(await link(asA, id, { d: 1 }));
    expect(download.headers()["content-disposition"]).toBe('attachment; filename="e2e-server-pixel.png"');

    // The redirect pages use for <img> tags goes to the same kind of link
    const redirect = await asA.request.get(`/api/file/index.php?r&f=${id}`, { maxRedirects: 0 });
    expect(redirect.status()).toBe(302);
    expect(redirect.headers()["location"]).toMatch(new RegExp(`^${BASE_URL}/api/file/stream\\.php\\?f=${id}&d=0&exp=\\d+&sig=[0-9a-f]{64}$`));
  } finally {
    await anonymous.dispose();
  }
});

/** The requests the app has made to the bucket for an object since the log was cleared */
async function bucketReads(key: string) {
  const log: { method: string; path: string }[] = await (await fetch(`${FAKE_S3_URL}/_log`)).json();
  return log.filter((entry) => entry.method === "GET" && entry.path === `/${BUCKET}/${key}`).length;
}

test("a file's link stays the same for the hour, and the browser's copy is used instead of the bucket", async ({ playwright, asA, tenants: { a } }) => {
  const id = (await upload(asA, assetTypeFile(a.assetTypeId), { name: "e2e-server-pixel.png", buffer: PNG })).json.response.id;
  const key = objectKey(row(id));
  const first = await link(asA, id);
  expect(await link(asA, id), "asking again gives the same link").toBe(first);
  const expires = Number(new URL(first).searchParams.get("exp"));
  expect(expires % 3600, "it expires on the hour").toBe(0);
  expect(expires).toBeGreaterThan(Date.now() / 1000 + 59);
  expect(expires).toBeLessThanOrEqual(Date.now() / 1000 + 3600 + 60);

  await fetch(`${FAKE_S3_URL}/_log`, { method: "DELETE" });
  const anonymous = await playwright.request.newContext();
  try {
    const response = await anonymous.get(first);
    expect(response.status()).toBe(200);
    const etag = response.headers()["etag"];
    expect(etag).toMatch(/^"[0-9a-f]{64}"$/);
    // A reload checks the copy it has, which is answered without asking the bucket
    expect((await anonymous.get(first, { headers: { "If-None-Match": etag } })).status()).toBe(304);
    expect((await anonymous.get(first, { headers: { "If-None-Match": `W/"other", W/${etag}` } })).status(), "a list of tags, weak").toBe(304);
    expect(await bucketReads(key), "only the first request read the bucket").toBe(1);

    // Renaming the file changes the name it's downloaded with, so the browser's copy is out of date
    expect(succeeded(await asA.api("/api/file/rename.php", { s3files_id: id, s3files_name: "e2e-server-renamed" }))).toBe(true);
    const renamed = await anonymous.get(first, { headers: { "If-None-Match": etag } });
    expect(renamed.status()).toBe(200);
    expect(renamed.headers()["content-disposition"]).toBe('inline; filename="e2e-server-renamed.png"');

    // The redirect pages use for <img> tags may be kept until shortly before its link expires
    const redirect = await asA.request.get(`/api/file/index.php?r&f=${id}`, { maxRedirects: 0 });
    if (expires - Date.now() / 1000 > 360) expect(redirect.headers()["cache-control"]).toMatch(/^private, max-age=\d+$/);
  } finally {
    await anonymous.dispose();
  }
});

test("a browser showing a file again uses its cached copy, not the bucket", async ({ browser, asA, tenants: { a } }) => {
  const id = (await upload(asA, assetTypeFile(a.assetTypeId), { name: "e2e-server-pixel.png", buffer: PNG })).json.response.id;
  // Playwright turns the browser's cache off while requests are being routed (as cacheCdn does), so this context
  // isn't routed: it has A's session and fetches the file from a page that needs nothing from a CDN
  const context = await browser.newContext({ baseURL: BASE_URL, storageState: await asA.request.storageState() });
  try {
    await fetch(`${FAKE_S3_URL}/_log`, { method: "DELETE" });
    const page = await context.newPage();
    await page.goto(`/api/file/index.php?f=${id}`); // A page on the site to fetch from (the file itself is sandboxed)
    const sizes = await page.evaluate(async (fileId) => {
      const sizes: number[] = [];
      for (let i = 0; i < 3; i++) {
        if (i > 0) await new Promise((resolve) => setTimeout(resolve, 1100)); // Seconds apart, as page views would be
        sizes.push((await (await fetch(`/api/file/index.php?r&f=${fileId}`)).arrayBuffer()).byteLength);
      }
      return sizes;
    }, id);
    expect(sizes).toEqual([PNG.length, PNG.length, PNG.length]);
    expect(await bucketReads(objectKey(row(id))), "fetched three times, read from the bucket once").toBe(1);
  } finally {
    await context.close();
  }
});

test("a link that has been changed or has expired is refused", async ({ playwright, asA, tenants: { a, b } }) => {
  const id = (await upload(asA, assetTypeFile(a.assetTypeId), { name: "e2e-server-pixel.png", buffer: PNG })).json.response.id;
  const url = new URL(await link(asA, id));
  const anonymous = await playwright.request.newContext();
  const changed = (changes: Record<string, string>) => {
    const copy = new URL(url);
    for (const [key, value] of Object.entries(changes)) copy.searchParams.set(key, value);
    return anonymous.get(copy.toString());
  };
  try {
    expect((await changed({})).status(), "control: the link as given").toBe(200);
    expect((await changed({ f: String(b.fileId) })).status(), "another file").toBe(403);
    expect((await changed({ d: "1" })).status(), "forced download").toBe(403);
    expect((await changed({ exp: String(Number(url.searchParams.get("exp")) + 3600) })).status(), "later expiry").toBe(403);
    expect((await changed({ sig: "0".repeat(64) })).status(), "another signature").toBe(403);
    expect((await anonymous.get(`${BASE_URL}/api/file/stream.php?f=${id}`)).status(), "no signature").toBe(403);

    // A link that has run out, signed as s3URL signs them (the key is derived from the JWT key setup/seed.php sets)
    const signed = (exp: number) => `${BASE_URL}/api/file/stream.php?f=${id}&d=0&exp=${exp}&sig=${sign(id, false, exp)}`;
    const now = Math.floor(Date.now() / 1000);
    expect((await anonymous.get(signed(now + 600))).status(), "control: a link signed here for ten minutes' time").toBe(200);
    expect((await anonymous.get(signed(now - 1))).status(), "expired").toBe(403);
    // s3URL won't sign a link that has already run out: a time in the past becomes a minute from now
    const past = new URL((await asA.api("/api/file/index.php", { f: id, e: "-1 hour" })).json.response.url);
    expect(Number(past.searchParams.get("exp"))).toBeGreaterThan(now);
    dbQuery("UPDATE s3files SET s3files_meta_physicallyStored = 0 WHERE s3files_id = ?", [id]);
    expect((await anonymous.get(url.toString())).status(), "the file has since been lost or deleted").toBe(404);
  } finally {
    await anonymous.dispose();
  }
});

test("files that could run script on this site are only ever downloaded", async ({ playwright, asA, tenants: { a } }) => {
  const html = Buffer.from("<script>alert(document.cookie)</script>");
  for (const name of ["e2e-server-page.html", "e2e-server-drawing.svg"]) {
    const id = (await upload(asA, assetTypeFile(a.assetTypeId), { name, mimeType: "text/html", buffer: html })).json.response.id;
    const anonymous = await playwright.request.newContext();
    try {
      const response = await anonymous.get(await link(asA, id));
      expect(response.headers()["content-type"], name).toBe("application/octet-stream");
      expect(response.headers()["content-disposition"], name).toMatch(/^attachment;/);
      expect(response.headers()["content-security-policy"], name).toContain("sandbox");
    } finally {
      await anonymous.dispose();
    }
  }
});

test("uploads are refused for another business's record, over 25MB, past the storage limit, or without an extension", async ({ asA, tenants: { a, b } }) => {
  const refused = async (fields: Fields, file: { name: string; buffer: Buffer }, message: string | RegExp) => {
    const response = await upload(asA, fields, file);
    expect(succeeded(response), `${file.name}\n${response.body.slice(0, 300)}`).toBe(false);
    expect(response.json?.error?.message).toMatch(message);
  };
  await refused(assetTypeFile(b.assetTypeId), { name: "e2e-server-b.png", buffer: PNG }, "Record not found");
  await refused(assetTypeFile(a.assetTypeId), { name: "e2e-server-large.bin", buffer: Buffer.alloc(25 * 1024 * 1024 + 1) }, "25MB");
  await refused(assetTypeFile(a.assetTypeId), { name: "e2e-server-noextension", buffer: PNG }, "extension");
  dbQuery("UPDATE instances SET instances_storageLimit = 10 WHERE instances_id = ?", [a.instanceId]);
  await refused(assetTypeFile(a.assetTypeId), { name: "e2e-server-full.png", buffer: PNG }, "Storage limit reached");
  dbQuery("UPDATE instances SET instances_storageLimit = 0 WHERE instances_id = ?", [a.instanceId]);
  expect(dbQuery("SELECT s3files_id FROM s3files WHERE s3files_original_name LIKE 'e2e-server-%'")).toHaveLength(0);

  // Control: exactly 25MB is fine
  const largest = await upload(asA, assetTypeFile(a.assetTypeId), { name: "e2e-server-largest.bin", buffer: Buffer.alloc(25 * 1024 * 1024) });
  expect(succeeded(largest), largest.body.slice(0, 300)).toBe(true);
});

test("another business's user can't get a link to the file", async ({ playwright, asA, tenants: { a, b, password } }) => {
  const id = (await upload(asA, assetTypeFile(a.assetTypeId), { name: "e2e-server-pixel.png", buffer: PNG })).json.response.id;
  const request = await playwright.request.newContext({ baseURL: BASE_URL });
  try {
    const asB = await newSession(request, b.users.full.email, password);
    const response = await asB.api("/api/file/index.php", { f: id });
    expect(succeeded(response)).toBe(false);
    expect(response.body).not.toContain("stream.php");
  } finally {
    await request.dispose();
  }
});

test("the direct upload endpoints are refused, and direct mode refuses the server's", async ({ asA, tenants: { a } }) => {
  for (const endpoint of ["/api/s3files/generateSignatureUppy.php", "/api/s3files/uploadSuccess.php"]) {
    const response = await asA.api(endpoint, { filename: "uploads/e2e/e2e-server-direct.png", contentType: "image/png", name: "uploads/e2e/e2e-server-direct.png", size: 1, typeid: 3, subtype: a.assetTypeId, originalName: "e2e-server-direct.png", public: 0 });
    expect(succeeded(response), endpoint).toBe(false);
    expect(response.body, endpoint).not.toContain(BROWSER_ENDPOINT);
  }
  dbQuery("UPDATE config SET config_value = 'Direct' WHERE config_key = 'FILES_TRANSFER_MODE'");
  const response = await upload(asA, assetTypeFile(a.assetTypeId), { name: "e2e-server-pixel.png", buffer: PNG });
  expect(succeeded(response)).toBe(false);
  // Control: in direct mode the browser is given a pre-signed link to the browser endpoint, as before
  const signature = await asA.api("/api/s3files/generateSignatureUppy.php", { filename: "uploads/e2e/e2e-server-direct.png", contentType: "image/png" });
  expect(signature.json?.url).toContain("browser-endpoint.invalid");
});

test("the uploader on a page sends files to the server, and the page never mentions the bucket", async ({ page, tenants: { a, password } }) => {
  await cacheCdn(page.context());
  await login(page, a.users.full.email, password);
  const html = await (await page.request.get(`/asset.php?id=${a.assetTypeId}`)).text();
  expect(html).toContain("/api/s3files/upload.php");
  expect(html).not.toContain("generateSignatureUppy");
  for (const value of [BROWSER_ENDPOINT, "browser-endpoint", FAKE_S3_URL, BUCKET]) expect(html, value).not.toContain(value);

  const requests: string[] = [];
  page.on("request", (request) => requests.push(request.url()));
  await page.goto(`/asset.php?id=${a.assetTypeId}`, { waitUntil: "domcontentloaded" });
  await page.locator('a[href="#typeFiles"]').click();
  await page.locator("#typeFiles input.uppy-Dashboard-input").first().setInputFiles({ name: "e2e-server-journey.png", mimeType: "image/png", buffer: PNG });
  await page.locator("#typeFiles .uppy-StatusBar-actionBtn--upload").click();
  await expect.poll(() => dbQuery("SELECT s3files_id FROM s3files WHERE s3files_original_name = 'e2e-server-journey.png' AND instances_id = ?", [a.instanceId]).length).toBe(1);
  expect(requests.filter((url) => url.startsWith(`${BASE_URL}/api/s3files/upload.php`))).toHaveLength(1);
  expect(requests.filter((url) => url.startsWith(FAKE_S3_URL) || url.includes("browser-endpoint") || url.includes("generateSignatureUppy") || url.includes("uploadSuccess"))).toEqual([]);
});

test("documents get the business's logo straight from the bucket", async ({ asA, tenants: { a } }) => {
  // projectInvoice.php embeds the logo as a data URI (bCMS::s3DataUri), which reads the file from the bucket itself
  const id = (await upload(asA, { typeid: 10, subtype: a.instanceId, public: 0, type: "INSTANCE-LOGO" }, { name: "e2e-server-logo.png", buffer: PNG })).json.response.id;
  dbQuery("UPDATE instances SET instances_logo = ? WHERE instances_id = ?", [id, a.instanceId]);
  const document = await asA.page(`/project/projectInvoice.php?id=${a.projectId}&type=quote&preview=1&instancelogo=1`);
  // It's written into the page's script escaped for JS, so look for the part of the base64 that's only letters and digits
  expect(document.body).toContain("base64");
  expect(document.body).toContain(PNG.toString("base64").slice(0, 40));
});
