# RC1 verification summary

Local synthetic testing only; no remote production or staging server accessed.

| Log | PASS assertions |
|---|---:|
| documents-extended.php.log | 122 |
| frontend.log | 7 |
| http-regression.log | 122 |
| install-cli.php.log | 4 |
| legacy-costs.php.log | 43 |
| php-lint.log | 0 |
| rc1-browser.log | 18 |
| rc1-options.log | 73 |
| rc1.php.log | 78 |
| runtime-guard.php.log | 5 |
| upgrade.php.log | 6 |

47 PHP files passed syntax checking. All .js/.cjs files passed node --check, including app.js and i18n.js. Uploaded TXT, DOCX and searchable PDF were exercised through the actual multipart API; browser actions were exercised in a separate headless Chrome profile.

Production credentials, private configuration, database files and runtime binaries are excluded. Known synthetic test accounts remain in test fixtures only.
