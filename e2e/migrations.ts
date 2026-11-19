import fs from "fs";
import path from "path";

/**
 * Migrations that share a version or a class name. Phinx refuses to run any migration while two clash, and the
 * container's migrate.sh starts the app anyway, so a clash between two branches merged around the same time
 * leaves the database unmigrated in production.
 */
export function migrationClashes() {
  const dir = path.join(__dirname, "..", "db", "migrations");
  const versions: Record<string, string[]> = {};
  const classes: Record<string, string[]> = {};
  for (const file of fs.readdirSync(dir).filter((f) => f.endsWith(".php")).sort()) {
    (versions[file.split("_")[0]] ??= []).push(file);
    const name = fs.readFileSync(path.join(dir, file), "utf8").match(/class\s+(\w+)\s+extends/)?.[1] ?? "";
    (classes[name] ??= []).push(file);
  }
  const clashing = (byKey: Record<string, string[]>) => Object.values(byKey).filter((files) => files.length > 1);
  return { versions: clashing(versions), classNames: clashing(classes) };
}
