# MyFitness Touchscreen Builder

## Usage

Run from project root.

- Rebuild all clubs (default):
  - `php build.php`
- Rebuild selected clubs only:
  - `php build.php --clubs=Saga,Aleja`
- Rebuild selected clubs and create zip files:
  - `php build.php --clubs=Saga,Aleja --zip`

## Notes

- Default output is written to `builds/<club>`.
- Zip files are created only when `--zip` is used in batch mode.
