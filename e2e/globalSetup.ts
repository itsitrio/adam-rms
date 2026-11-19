import { execFileSync } from "child_process";
import path from "path";
import { APP_ENV, PHP_BINARY } from "./env";
import { migrationClashes } from "./migrations";

/**
 * Brings the database to a known state before the suite runs: applies every
 * migration, runs the Phinx seeders (default positions etc.), then writes the
 * config the first-run setup form would otherwise ask for and makes sure the
 * test super admin exists with a known password (see setup/seed.php), and
 * creates the two businesses the tenant isolation tests use (setup/tenants.php).
 *
 * Requires a running MySQL reachable with the credentials in ./env.ts.
 */
export default function globalSetup() {
  const repoRoot = path.resolve(__dirname, "..");
  const run = (args: string[]) =>
    execFileSync(PHP_BINARY, args, {
      cwd: repoRoot,
      env: { ...process.env, ...APP_ENV },
      stdio: ["ignore", "inherit", "inherit"],
    });

  // Phinx won't migrate at all while two migrations clash, and only says so without -q
  const clashes = migrationClashes();
  if (clashes.versions.length || clashes.classNames.length) {
    throw new Error(`Migrations clash, so Phinx won't run any of them. Give each its own version and class name: ${JSON.stringify(clashes)}`);
  }
  run(["vendor/bin/phinx", "migrate", "-q"]);
  run(["vendor/bin/phinx", "seed:run", "-q"]);
  run(["e2e/setup/seed.php"]);
  execFileSync(PHP_BINARY, ["e2e/setup/tenants.php"], { cwd: repoRoot, env: { ...process.env, ...APP_ENV }, stdio: ["ignore", "ignore", "inherit"] });
}
