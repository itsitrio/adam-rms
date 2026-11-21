import { cacheCdn } from "../cdn";
import { login } from "../fixtures";
import { expect, test } from "../tenants";

/** The version number at the bottom of every page links to this fork's releases, not the upstream project's */
test("the footer version links to this repository's releases", async ({ page, tenants: { a, password } }) => {
  await cacheCdn(page.context());
  await login(page, a.users.full.email, password);
  for (const path of ["/", "/assets.php", "/clients.php"]) {
    await page.goto(path);
    const href = await page.locator("#versionLink").getAttribute("href");
    expect(href, path).toBe("https://github.com/itsitrio/adam-rms/releases");
  }
});
