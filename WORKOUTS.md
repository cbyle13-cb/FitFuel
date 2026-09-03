# Workout library

The Workouts tab adds 30 exercises with locally stored demonstration photographs and instructions; personal routines; editable set-by-set logs; resumable saved sessions; history; and charts of load, repetitions/hold time, and training volume. Progress also includes a body-weight chart.

Personalization includes exercise swaps and ordering, 1–12 sets, rep/hold ranges, starting weight, equipment weight increments/caps, and rest guidance. Weights use pounds per dumbbell or single implement. Paired dumbbells and unilateral reps are not multiplied in volume charts. Three full-body routines are starting templates, not assigned automatically to an account.

Progression uses the latest dated completed session for each exercise. It raises reps first, then increases weight by the configured increment after all planned sets reach the upper range with good reported effort. Unknown effort, incomplete sets, changed set counts, mixed loads, hard effort, and discomfort prevent load increases. Timed exercises increase seconds; equipment caps and fixed loads are respected. Suggestions remain editable.

## Storage and deployment

Deploy the repository together, including `API/workouts.php`, the three workout JS/CSS files, `exercises.json`, and `assets/exercises/`. This is the existing PHP/MySQL Hostinger application, not a replacement hosted service.

No schema migration is required. The existing workout_logs table stores versioned JSON in notes. Template/draft records use completed=0; finished sessions use completed=1. Existing plain-text workout logs remain readable. All reads, updates, and deletes are scoped to the authenticated user. No production database or account data is included in the repository.

New record creation uses a per-record UUID and a transaction locking the user row to prevent duplicate inserts after a lost network response. There are explicit Save routine, Save for later and Finish workout actions. Unsaved edits prompt before navigation. Offline saving is not implemented; a failed save retains the form and displays the error.

Before a production update, retain the existing Hostinger database backup. To roll back, restore the prior application files; leave database records in place. Do not import a replacement database.

## Validation

`node --test tests/workout-core.test.cjs` checks progression and metric rules.

`tests/workout-ui.test.cjs` additionally requires jsdom and exercises create/edit/save/start/finish/history/charts/account separation with a simulated API. Run with jsdom available on NODE_PATH.

PHP syntax was checked with php-parser. A PHP/MySQL execution environment and an authenticated production account were not available for live endpoint integration testing. After deployment, save a personal routine, finish a test session, reload it, and verify it from another device. Exercise photos are verified image files; they demonstrate positions rather than continuous animation.

Exercise data source: https://github.com/yuhonas/free-exercise-db (declared Unlicense).
