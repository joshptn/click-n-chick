import { readFileSync } from "node:fs";
import path from "node:path";
import { fileURLToPath } from "node:url";

/**
 * The seeded accounts the e2e suite signs in as.
 *
 * Read from the same SEED_* values DatabaseSeeder uses - server/.env, or the
 * environment, which wins - so no address or password is written into the
 * public repository.
 */
const SERVER_ENV = path.resolve(path.dirname(fileURLToPath(import.meta.url)), "../../server/.env");

function seedValues() {
  const values = {};

  try {
    for (const line of readFileSync(SERVER_ENV, "utf8").split(/\r?\n/)) {
      const match = line.match(/^(SEED_[A-Z_]+)=(.*)$/);

      if (match) values[match[1]] = match[2].trim().replace(/^"(.*)"$/, "$1");
    }
  } catch {
    // No server/.env: the environment must supply them.
  }

  return { ...values, ...Object.fromEntries(Object.entries(process.env).filter(([key]) => key.startsWith("SEED_"))) };
}

const seed = seedValues();

function account(emailKey) {
  const email = seed[emailKey];
  const password = seed.SEED_ACCOUNT_PASSWORD;

  if (!email || !password) {
    throw new Error(`${emailKey} and SEED_ACCOUNT_PASSWORD must be set in server/.env to run the e2e suite.`);
  }

  return { email, password };
}

export const CUSTOMER = account("SEED_CUSTOMER_EMAIL");
export const STAFF = account("SEED_ADMIN_EMAIL");
export const MANAGER = account("SEED_SUPER_ADMIN_EMAIL");
