# Contributing to Notify with GOWA

Thank you for your interest in contributing to **Notify with GOWA**! We welcome bug reports, feature suggestions, documentation improvements, and pull requests from developers around the world.

## Code of Conduct

This project and everyone participating in it is governed by the [Code of Conduct](CODE_OF_CONDUCT.md). By participating, you are expected to uphold this code.

## How Can I Contribute?

### Reporting Bugs
Before creating bug reports, please check the [existing issues](https://github.com/OmniTx/notify-with-gowa/issues) to see if the problem has already been reported.

When filing a bug report, please include:
- A clear, descriptive title.
- Steps to reproduce the issue.
- Your WordPress, WooCommerce, and PHP versions.
- Any relevant server error logs or screenshots.

### Suggesting Enhancements
Feature requests are always welcome! Open an issue on GitHub describing:
- The problem you are trying to solve.
- Your proposed solution or workflow.
- Why this enhancement would benefit other store owners.

### Submitting Pull Requests (PRs)
1. **Fork the Repository**: Fork the repository to your GitHub account and clone it locally.
2. **Create a Feature Branch**:
   ```bash
   git checkout -b feature/my-new-feature
   ```
3. **Coding Standards & Security**:
   - Write clean, readable code following WordPress Coding Standards.
   - Sanitize all inputs using `sanitize_text_field()`, `sanitize_textarea_field()`, etc.
   - Escape all outputs using `esc_html()`, `esc_attr()`, `esc_url()`, etc.
   - Use WordPress nonces for form submissions and AJAX handlers.
   - Ensure all translation strings use the `notify-with-gowa` text domain.
4. **Test Your Changes**:
   - Check PHP syntax: `php -l <filename.php>`
   - Ensure the GitHub Actions **PHP Coding Standards** workflow passes on your branch.
5. **Submit a PR**:
   - Push your branch to GitHub and open a Pull Request against the `main` branch.
   - Describe what changed and include screenshots if the UI was modified.

## Licensing

By contributing to **Notify with GOWA**, you agree that your contributions will be licensed under the project's [GNU General Public License v3.0 or later](LICENSE).