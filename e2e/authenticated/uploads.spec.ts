import { cacheCdn } from "../cdn";
import { login } from "../fixtures";
import { BASE_URL } from "../env";
import { startFakeS3, uploadFile, BUCKET, type FakeS3 } from "../s3";
import { test, expect, dbQuery, newSession, succeeded } from "../tenants";

// The browser uploads straight to S3 (src/assets/templates/uppy.twig): generateSignatureUppy.php signs a PUT, and
// uploadSuccess.php registers the stored file. These check that a user can only write, and register, the key the server chose.

let s3: FakeS3;
test.beforeEach(async () => {
  s3 = await startFakeS3();
});
test.afterEach(async ({ tenants: { a } }) => {
  await s3.stop();
  dbQuery("UPDATE instances SET instances_storageLimit = 0 WHERE instances_id = ?", [a.instanceId]);
  dbQuery("DELETE FROM s3files WHERE s3files_original_name = 'Uploaded_by_e2e.pdf' OR s3files_original_name = 'Uploaded by e2e.pdf' OR s3files_name IN ('e2e-journey', 'e2e-photo')");
});

const sign = (filename: string) => ({ filename, contentType: "application/pdf" });

test("api/s3files/generateSignatureUppy.php signs a key it chose, not the one the browser asked for", async ({ asA }) => {
  // B's seeded file, as a key A might know or guess
  const foreignKey = `e2e/e2e-tenant-b-file.pdf`;
  for (const filename of [foreignKey, "uploads/E2E-FILE/../../e2e/e2e-tenant-b-file.pdf", `uploads/E2E-FILE/1700000000000-123.pdf`]) {
    const signature = await asA.api("/api/s3files/generateSignatureUppy.php", sign(filename));
    expect(signature.json?.key, `${filename}\n${signature.body.slice(0, 300)}`).toMatch(/^uploads\/[A-Za-z0-9_-]+\/\d+-\d+\.pdf$/);
    expect(signature.json.key).not.toBe(filename);
    expect(new URL(signature.json.url).pathname).toBe(`/${BUCKET}/${signature.json.key}`);
  }
  // Two requests never share a key
  const [first, second] = await Promise.all([1, 2].map(() => asA.api("/api/s3files/generateSignatureUppy.php", sign("uploads/E2E-FILE/x.pdf"))));
  expect(first.json.key).not.toBe(second.json.key);
  // The extension has to be a plain one
  for (const filename of ["uploads/E2E-FILE/noextension", "uploads/E2E-FILE/x.p/df", "uploads/E2E-FILE/x.php%00.pdf/"]) {
    expect(succeeded(await asA.api("/api/s3files/generateSignatureUppy.php", sign(filename))), filename).toBe(false);
  }
});

test("api/s3files/uploadSuccess.php registers a file uploaded with a key it issued, at its real size", async ({ asA, tenants: { a } }) => {
  const contents = Buffer.alloc(4321, "x");
  const signature = await asA.api("/api/s3files/generateSignatureUppy.php", sign("uploads/E2E-FILE/report.pdf"));
  const key: string = signature.json.key;
  s3.objects.set(key, contents);
  // The browser says the file is 1 byte
  const upload = await asA.api("/api/s3files/uploadSuccess.php", { name: key, size: 1, typeid: 11, subtype: a.locationId, originalName: "Uploaded by e2e.pdf", public: 0 });
  expect(succeeded(upload), upload.body.slice(0, 300)).toBe(true);
  const [row] = dbQuery<{ s3files_path: string; s3files_filename: string; s3files_extension: string; s3files_meta_size: number; instances_id: number }>(
    "SELECT s3files_path, s3files_filename, s3files_extension, s3files_meta_size, instances_id FROM s3files WHERE s3files_id = ?", [upload.json.response.id]);
  expect(`${row.s3files_path}/${row.s3files_filename}.${row.s3files_extension}`).toBe(key);
  expect(Number(row.s3files_meta_size)).toBe(contents.length);
  expect(row.instances_id).toBe(a.instanceId);
  // A key is registered once
  expect(succeeded(await asA.api("/api/s3files/uploadSuccess.php", { name: key, size: 1, typeid: 11, subtype: a.locationId, originalName: "Uploaded by e2e.pdf", public: 0 })), "the same key again").toBe(false);
});

test("api/s3files/uploadSuccess.php won't register a key it didn't issue to this user in this business", async ({ asA, playwright, tenants: { a, b, sharedUser, password } }) => {
  const register = (session: typeof asA, name: string) =>
    session.api("/api/s3files/uploadSuccess.php", { name, size: 1, typeid: 11, subtype: a.locationId, originalName: "Uploaded by e2e.pdf", public: 0 });
  const rows = () => dbQuery("SELECT s3files_id FROM s3files WHERE s3files_original_name = 'Uploaded_by_e2e.pdf' OR s3files_original_name = 'Uploaded by e2e.pdf'").length;
  const before = rows();

  // B's stored file: pointing a row in A at it would let A download it
  s3.objects.set("e2e/e2e-tenant-b-file.pdf", Buffer.from("B's file"));
  expect(succeeded(await register(asA, "e2e/e2e-tenant-b-file.pdf")), "B's file's key").toBe(false);
  // A key that looks like one the browser would make, but that the server never issued
  s3.objects.set("uploads/LOCATION-FILE/1700000000000-12345678901.pdf", Buffer.from("x"));
  expect(succeeded(await register(asA, "uploads/LOCATION-FILE/1700000000000-12345678901.pdf")), "a made-up key").toBe(false);

  const request = await playwright.request.newContext({ baseURL: BASE_URL });
  try {
    // A key issued to someone else
    const asB = await newSession(request, b.users.full.email, password);
    const bSignature = await asB.api("/api/s3files/generateSignatureUppy.php", sign("uploads/E2E-FILE/b.pdf"));
    s3.objects.set(bSignature.json.key, Buffer.from("B's upload"));
    expect(succeeded(await register(asA, bSignature.json.key)), "a key issued to B").toBe(false);

    // A key issued while the user was in another business
    const asShared = await newSession(request, sharedUser.email, password);
    expect(succeeded(await asShared.api("/api/instances/switch.php", { instances_id: b.instanceId }))).toBe(true);
    const sharedSignature = await asShared.api("/api/s3files/generateSignatureUppy.php", sign("uploads/E2E-FILE/shared.pdf"));
    s3.objects.set(sharedSignature.json.key, Buffer.from("upload in B"));
    expect(succeeded(await asShared.api("/api/instances/switch.php", { instances_id: a.instanceId }))).toBe(true);
    expect(succeeded(await register(asShared, sharedSignature.json.key)), "a key issued in B, registered in A").toBe(false);
  } finally {
    await request.dispose();
  }

  // A key that was issued but never uploaded to
  const unused = await asA.api("/api/s3files/generateSignatureUppy.php", sign("uploads/E2E-FILE/never.pdf"));
  expect(succeeded(await register(asA, unused.json.key)), "nothing stored under the key").toBe(false);
  expect(rows()).toBe(before);
});

test("api/s3files/uploadSuccess.php enforces the business's storage limit using the stored size", async ({ asA, tenants: { a } }) => {
  const [{ used }] = dbQuery<{ used: number | null }>("SELECT SUM(s3files_meta_size) AS used FROM s3files WHERE instances_id = ? AND s3files_meta_deleteOn IS NULL AND s3files_meta_physicallyStored = 1", [a.instanceId]);
  dbQuery("UPDATE instances SET instances_storageLimit = ? WHERE instances_id = ?", [Number(used ?? 0) + 1000, a.instanceId]);
  // Claims to be 1 byte, but is over the limit
  const signature = await asA.api("/api/s3files/generateSignatureUppy.php", sign("uploads/E2E-FILE/big.pdf"));
  s3.objects.set(signature.json.key, Buffer.alloc(5000, "x"));
  const tooBig = await asA.api("/api/s3files/uploadSuccess.php", { name: signature.json.key, size: 1, typeid: 11, subtype: a.locationId, originalName: "Uploaded by e2e.pdf", public: 0 });
  expect(succeeded(tooBig), tooBig.body.slice(0, 300)).toBe(false);
  expect(s3.objects.has(signature.json.key), "the refused file is removed from the bucket").toBe(false);
  // Control: a file that fits
  const fits = await uploadFile(asA, s3, { typeid: 11, subtype: a.locationId }, Buffer.alloc(500, "x"));
  expect(succeeded(fits), fits.body.slice(0, 300)).toBe(true);
});

test("a file can be uploaded from a location's files page", async ({ page, tenants: { a, password } }) => {
  await cacheCdn(page.context());
  await s3.routeBrowser(page.context());
  await login(page, a.users.full.email, password);
  await page.goto(`/location/?id=${a.locationId}&files`, { waitUntil: "domcontentloaded" });
  await page.locator(".uppy-Dashboard-input").first().setInputFiles({ name: "e2e-journey.pdf", mimeType: "application/pdf", buffer: Buffer.alloc(2048, "p") });
  const registered = page.waitForResponse((response) => response.url().includes("/api/s3files/uploadSuccess.php"));
  await page.locator(".uppy-StatusBar-actionBtn--upload").click();
  const result = await (await registered).json();
  expect(result.result, JSON.stringify(result)).toBe(true);
  const [row] = dbQuery<{ s3files_path: string; s3files_filename: string; s3files_extension: string; s3files_meta_size: number; s3files_meta_subType: number }>(
    "SELECT s3files_path, s3files_filename, s3files_extension, s3files_meta_size, s3files_meta_subType FROM s3files WHERE s3files_id = ?", [result.response.id]);
  const key = `${row.s3files_path}/${row.s3files_filename}.${row.s3files_extension}`;
  expect(key).toMatch(/^uploads\/LOCATION-FILE\/\d+-\d+\.pdf$/);
  expect(s3.objects.get(key)?.length, "the row points at the stored file").toBe(2048);
  expect(Number(row.s3files_meta_size)).toBe(2048);
  expect(row.s3files_meta_subType).toBe(a.locationId);
});

test("the uploader can resize an image and remove its plain background before uploading it", async ({ page, tenants: { a, password } }) => {
  await cacheCdn(page.context());
  await s3.routeBrowser(page.context());
  await login(page, a.users.full.email, password);
  const filesPage = `/location/?id=${a.locationId}&files`;
  await page.goto(filesPage, { waitUntil: "domcontentloaded" });
  // A 1000x600 photo: a red box on a white background, with the background showing through a hole in its middle (like
  // the middle of a coiled cable), and a small white highlight on it
  const photo = Buffer.from(await page.evaluate(() => {
    const canvas = document.createElement("canvas");
    canvas.width = 1000;
    canvas.height = 600;
    const context = canvas.getContext("2d")!;
    context.fillStyle = "#ffffff";
    context.fillRect(0, 0, 1000, 600);
    context.fillStyle = "#ff0000";
    context.fillRect(300, 200, 400, 200);
    context.fillStyle = "#ffffff";
    context.fillRect(400, 250, 200, 100);
    context.fillRect(320, 220, 10, 10);
    return canvas.toDataURL("image/jpeg", 0.95).split(",")[1];
  }), "base64");
  /** Uploads the photo from a fresh page with the given options, and returns the stored file's row and contents */
  const upload = async (resize: string, removeBackground: boolean) => {
    await page.goto(filesPage, { waitUntil: "domcontentloaded" });
    await page.locator('select[id$="Resize"]').selectOption(resize);
    if (removeBackground) await page.locator('label[for$="RemoveBackground"]').click();
    await page.locator(".uppy-Dashboard-input").first().setInputFiles({ name: "e2e-photo.jpg", mimeType: "image/jpeg", buffer: photo });
    const registered = page.waitForResponse((response) => response.url().includes("/api/s3files/uploadSuccess.php"));
    await page.locator(".uppy-StatusBar-actionBtn--upload").click();
    const result = await (await registered).json();
    expect(result.result, JSON.stringify(result)).toBe(true);
    const [row] = dbQuery<{ s3files_path: string; s3files_filename: string; s3files_extension: string; s3files_meta_size: number; s3files_original_name: string }>(
      "SELECT s3files_path, s3files_filename, s3files_extension, s3files_meta_size, s3files_original_name FROM s3files WHERE s3files_id = ?", [result.response.id]);
    const stored = s3.objects.get(`${row.s3files_path}/${row.s3files_filename}.${row.s3files_extension}`)!;
    expect(Number(row.s3files_meta_size)).toBe(stored.length);
    return { row, stored };
  };
  /** The image's size, and the colour and alpha of a corner, the middle (the hole), the box and the highlight, as fractions of the size */
  const inspect = (image: Buffer, type: string) => page.evaluate(async ({ base64, type }) => {
    const bitmap = await createImageBitmap(await (await fetch(`data:${type};base64,${base64}`)).blob());
    const canvas = document.createElement("canvas");
    canvas.width = bitmap.width;
    canvas.height = bitmap.height;
    const context = canvas.getContext("2d")!;
    context.drawImage(bitmap, 0, 0);
    return { width: bitmap.width, height: bitmap.height, corner: [...context.getImageData(1, 1, 1, 1).data], middle: [...context.getImageData(bitmap.width / 2, bitmap.height / 2, 1, 1).data],
      box: [...context.getImageData(Math.round(bitmap.width * 0.35), Math.round(bitmap.height * 0.5), 1, 1).data],
      highlight: [...context.getImageData(Math.round(bitmap.width * 0.325), Math.round(bitmap.height * 0.375), 1, 1).data] };
  }, { base64: image.toString("base64"), type });

  // By default the photo is uploaded as it is
  const original = await upload("0", false);
  expect(original.stored.equals(photo)).toBe(true);
  expect(original.row.s3files_extension).toBe("jpg");

  // Resized: still a JPEG, at most 800px across
  const resized = await upload("800", false);
  expect(resized.row.s3files_extension).toBe("jpg");
  expect(resized.row.s3files_original_name).toBe("e2e-photo.jpg");
  const resizedImage = await inspect(resized.stored, "image/jpeg");
  expect([resizedImage.width, resizedImage.height]).toEqual([800, 480]);
  expect(resizedImage.corner[3]).toBe(255);

  // With the background removed it's saved as a PNG whose white edge and hole are transparent, and whose red box and its
  // small highlight aren't
  const cutOut = await upload("800", true);
  expect(cutOut.row.s3files_extension).toBe("png");
  expect(cutOut.row.s3files_original_name).toBe("e2e-photo.png");
  expect(cutOut.stored.subarray(1, 4).toString()).toBe("PNG");
  const cutOutImage = await inspect(cutOut.stored, "image/png");
  expect([cutOutImage.width, cutOutImage.height]).toEqual([800, 480]);
  expect(cutOutImage.corner[3]).toBe(0);
  expect(cutOutImage.middle[3], "the hole in the middle").toBe(0);
  expect(cutOutImage.box[3]).toBe(255);
  expect(cutOutImage.box[0]).toBeGreaterThan(200);
  expect(cutOutImage.box[1]).toBeLessThan(50);
  expect(cutOutImage.highlight[3], "the highlight").toBe(255);
  expect(cutOutImage.highlight[1]).toBeGreaterThan(200);
});
