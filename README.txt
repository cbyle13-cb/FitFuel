FitFuel

Database-backed FitFuel application with:
- User registration/login and PHP sessions
- User profiles
- Food logging
- Barcode lookup via backend
- Recipes and meal planning
- Progress tracking
- Workout logging

Deployment
- Repository deploys to Hostinger public_html from main.
- Database credentials are NOT stored in this repository.
- Hostinger must contain fitfuel_private.php one level above public_html.
- Run FitFuel_database_upgrade.sql in the Hostinger MySQL database before using the upgraded application.

Do not commit fitfuel_private.php, .env files, or database passwords.
