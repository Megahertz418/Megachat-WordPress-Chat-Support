# Contributing to Megachat Support

Thanks for your interest in contributing! 🙌  
We welcome bug reports, feature requests, and pull requests.

## How to Contribute

### 1. Reporting Bugs
- Use the [Issues](../../issues) tab to open a new bug report.
- Please include:
  - Steps to reproduce
  - Expected behavior
  - Actual behavior
  - Screenshots or logs if possible

### 2. Requesting Features
- Use the [Issues](../../issues) tab with the **Feature request** template.
- Explain the problem the feature would solve, not just the solution.

### 3. Pull Requests
- Fork the repository and create a new branch:  
  `git checkout -b feature/my-new-feature`
- Make sure your code:
  - Follows WordPress coding standards (PHP, JS, CSS).
  - Includes comments and docblocks where needed.
- Commit messages should follow [Conventional Commits](https://www.conventionalcommits.org/):  
  - `feat: add telegram webhook secret helper`
  - `fix: sanitize HTML output in chat widget`
  - `docs: update README with screenshots`
- Push your branch and open a Pull Request against `main`.

### 4. Code of Conduct
Be respectful and constructive.  
Harassment or discrimination of any kind will not be tolerated.

## Development Notes
- **Requirements:** PHP 7.4+ (tested up to PHP 8.2), WordPress 5.8+
- **Folder structure:**
  - `megachat-support.php` (main plugin)
  - `assets/` (CSS, JS, HTML, images)
  - `readme.txt` (WordPress plugin info)
  - `license.txt` (GPL v2 license)
- Please **do not commit API keys** or sensitive data.

Thank you for helping make Megachat Support better! 🚀