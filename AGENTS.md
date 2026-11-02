## Project Description
Laravel Versioned Storage is a small package, that provides simple, non interfering write operations for one or many files on any supported filesystem driver.

It primarily handles the storage of files in a versioned manner, where writing the file or directory multiple times is a new version. This is very important when 
write operations might be run from different processes, where writing to the same destination might interfere, even with locks, since the driver itself might stall, 
creating a race condition. Versions and the reference to the current version are stored in the Database to provide a common storage between installations that
can be changed transactionally.

## Important Commands
**Code Style / Formatting**: `composer format`
**Run Tests**: `composer test`
**Check Code Quality**: `composer analyse`

## Tests
There are multiple kinds of tests for this package.
### Specification
These tests define how this package is intended to be consumed by a generic Laravel application.

## Conventions
Use the common Laravel coding and architectural conventions. This is a package for Laravel, so it should follow the same standards.