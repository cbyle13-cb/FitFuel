Run from the repository root:

```sh
npm install --prefix /tmp/fitfuel-test-deps jsdom@26
NODE_PATH=/tmp/fitfuel-test-deps/node_modules node --test tests/*.test.cjs
php tests/nutrition-core.test.php
php tests/family-core.test.php
php tests/recipe-images.test.php
find API -name '*.php' -exec php -l {} \;
```

The nutrition integration test also requires MariaDB server/client binaries and PHP mysqli. It creates a temporary database and test accounts, starts a local PHP server, and checks the live endpoints without using production credentials or data:

```sh
python3 tests/nutrition-integration.py
python3 tests/family-integration.py
python3 tests/meal-types-integration.py
python3 tests/recipe-photos-integration.py
python3 tests/recipe-removal-integration.py
```
