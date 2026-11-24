import { readFileSync, writeFileSync } from "node:fs";
const files = [
  "src/components/ContactBlock.astro",
  "src/components/Reviews.astro",
  "src/components/Footer.astro",
];
for (const f of files) {
  let t = readFileSync(f, "utf8");
  t = t.replaceAll("#7e847d", "#8d938b");
  writeFileSync(f, t, "utf8");
  console.log("ok", f);
}
