import { execFileSync } from "node:child_process";
import { existsSync, mkdirSync, writeFileSync } from "node:fs";
import path from "node:path";
import { fileURLToPath } from "node:url";

const HERE = path.dirname(fileURLToPath(import.meta.url));
const SERVER = path.resolve(HERE, "../../server");

const artisan = (...args) => execFileSync("php", ["artisan", ...args], { cwd: SERVER, encoding: "utf8", timeout: 120_000 });

export default function globalSetup() {
  if (existsSync(path.join(SERVER, "bootstrap/cache/config.php"))) {
    throw new Error("server/bootstrap/cache/config.php exists. Run `php artisan config:clear` first.");
  }

  if (artisan("tinker", "--execute", "echo config('database.default');").trim().split(/\s+/).pop() !== "sqlite") {
    throw new Error("The e2e run is not pointed at its own SQLite database.");
  }

  mkdirSync(path.dirname(process.env.DB_DATABASE), { recursive: true });
  writeFileSync(process.env.DB_DATABASE, "");

  artisan("migrate", "--force");
  artisan("db:seed", "--class=AccountSeeder", "--force");
  artisan("tinker", "--execute", `require '${path.join(HERE, "fixtures.php").replaceAll("\\", "/")}';`);
}
