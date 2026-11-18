import { dbQuery } from "../tenants";
import { expect, test } from "../fixtures";

/**
 * The loan and repayment forms in a browser. Their money boxes are text, so an amount typed with a currency symbol or
 * a thousands separator reaches the server as typed (Safari and Firefox send nothing at all from a number box holding
 * "£1,250"), and a box left empty is pointed out before anything is sent.
 */
test("a loan and a repayment can be recorded with amounts typed as people type them", async ({ page }) => {
  const posts: string[] = [];
  page.on("request", (r) => { if (r.url().includes("/api/reports/liabilities/")) posts.push(r.url()); });
  const name = `E2E form loan ${Date.now()}`;
  await page.goto("/reports/liabilities.php");
  await page.locator("#liabilityAdd").click();
  await page.locator("#liabilityName").fill(name);
  await page.locator("#liabilitySave").click();
  // Nothing is sent while the amount borrowed is empty
  await expect(page.locator("#liabilityAmount")).toHaveClass(/is-invalid/);
  await expect(page.locator("#liabilityForm .formMissing")).toBeVisible();
  expect(posts).toEqual([]);

  await page.locator("#liabilityAmount").fill("£12,500");
  await page.locator("#liabilitySave").click();
  await page.waitForURL(/\/reports\/liability\.php\?id=\d+/);
  await page.waitForLoadState("networkidle");
  const id = Number(new URL(page.url()).searchParams.get("id"));
  expect(dbQuery("SELECT liabilities_name n, liabilities_amount a FROM liabilities WHERE liabilities_id = ?", [id])).toEqual([{ n: name, a: 1250000 }]);

  await page.locator("#transactionAdd").click();
  await expect(page.locator("#transactionModal")).toBeVisible();
  await page.locator("#transactionAmount").fill("");
  await page.locator("#transactionSave").click();
  await expect(page.locator("#transactionAmount")).toHaveClass(/is-invalid/);
  expect(posts.filter((p) => p.includes("transaction.php"))).toEqual([]);

  await page.locator("#transactionAmount").fill("£1,250.50");
  await page.locator("#transactionInterest").fill("50");
  await page.locator("#transactionSave").click();
  await expect(page.locator("#liabilityBalance")).toHaveText("£11,299.50");
  expect(dbQuery("SELECT liabilityTransactions_amount a, liabilityTransactions_interest i FROM liabilityTransactions WHERE liabilities_id = ?", [id])).toEqual([{ a: 125050, i: 5000 }]);
  dbQuery("UPDATE liabilities SET liabilities_deleted = 1 WHERE liabilities_id = ?", [id]);
});
