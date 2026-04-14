# MyFitness Touchscreen Builder

## Usage

Run from project root.

- Rebuild all clubs (default):
  - `php build.php`
- Rebuild selected clubs only:
  - `php build.php --clubs=Saga,Aleja`
- Rebuild selected clubs and create zip files:
  - `php build.php --clubs=Saga,Aleja --zip`
- Rebuild selected clubs and deploy them to FTP:
  - `php build.php --clubs=Saga,Aleja --deploy`
- Deploy already-built club folders without rebuilding:
  - `php deploy.php --clubs=Saga,Aleja`

## FTP Deployment

Create `deploy.local.php` in the project root and fill in your FTP credentials.

Required settings:

- `host`
- `username`
- `password`
- `remote_root_dir` (the parent folder that contains the club folders, for example `/LV`)

Optional settings:

- `port` (defaults to `21`)
- `timeout` (defaults to `90` seconds)
- `secure` (`true` to use FTPS when available)
- `passive` (`true` by default)

FTP settings are always loaded from `deploy.local.php`.

## Notes

- Default output is written to `builds/<club>`.
- Zip files are created only when `--zip` is used in batch mode.
- FTP deployment mirrors each club folder to `<remote_root_dir>/<club>/touch`.
