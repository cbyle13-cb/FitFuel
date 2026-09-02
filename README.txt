FitFuel PWA v5

Added:
- Shared recipe database
- iPhone Notes recipe import via copy/paste
- Website recipe import workflow with copy/paste fallback
- Multiple family profiles with separate meal plans, food intake, water and progress
- Weekly automatic meal planning
- Per-user grocery checklist
- Backup/restore

The workout system is intentionally unchanged for the next version.

Important architecture notes:
- Current PWA stores data locally on the device.
- True multi-device family accounts/login require a backend/database and authentication.
- True automatic URL recipe extraction requires a hosted backend because many recipe sites block browser cross-origin requests.
- Grocery aggregation currently counts repeated ingredient entries rather than performing unit-aware quantity math.
