# Contributing to ELEGUARD RAIL

Thank you for your interest in **ELEGUARD RAIL**!

---

## 1. Project Maintenance & Governance Notice

> [!IMPORTANT]
> **Active Development Status: FROZEN**
> The founding engineering team has officially concluded active development on this repository. The codebase is released as an open-source academic research prototype under the [MIT License](LICENSE).
>
> The original authors are no longer reviewing pull requests, actively maintaining the issue tracker, or providing engineering support. However, external developers, students, researchers, and wildlife conservation organizations are strongly encouraged to **fork** the repository and continue independent development within their own forks and community branches.

---

## 2. Forking and Independent Development

If you wish to enhance or modify ELEGUARD RAIL:

1. **Fork the Repository:** Click the **Fork** button at the top right of the [GitHub repository page](https://github.com/deatharoundthecorner/ELEGUARD-RAIL).
2. **Clone Your Fork:**
   ```bash
   git clone https://github.com/<your-username>/ELEGUARD-RAIL.git
   cd ELEGUARD-RAIL
   ```
3. **Create a Dedicated Topic Branch:**
   ```bash
   git checkout -b feature/your-feature-name
   ```
4. **Make Your Modifications:** Keep changes modular, focused, and well-documented.

---

## 3. Mandatory Safety & Architectural Rules

All downstream forks and future contributors must strictly uphold the foundational safety principles of ELEGUARD RAIL:

### A. Railway Safety Boundaries (Non-Negotiable)
- **Zero Locomotive Control:** Never implement features that purport to automatically control locomotive throttles, brakes, signaling relays, or railway interlocking systems.
- **Human Operational Primacy:** Official Sri Lanka Railways (SLR) General Rules, train orders (Form T.409/T.410), physical trackside signals, and authorized dispatch personnel retain 100% operational authority at all times.
- **No Safety-Critical Certification Claims:** Do not claim or represent this software as certified safety-critical railway software under CENELEC EN 50126/EN 50128/EN 50129 or IEC 61508.

### B. Artificial Intelligence & Vision Boundaries
- **Advisory Decision Support Only:** All AI inference (YOLOX-Tiny ONNX or future candidate models) must remain strictly advisory. Sighting approval, corridor risk assessment, and operational advisories require human verification.
- **Permissive Licensing:** Ensure that any integrated computer vision weights or libraries comply with permissive licensing (e.g. Apache 2.0, MIT, BSD) to preserve open redistribution rights.

### C. Data Privacy & Anti-Poaching
- **Anti-Poaching Coordinate Masking:** In public-facing or community-level interfaces, high-precision GPS coordinates of endangered wildlife must be protected or spatial-jittered to prevent poacher exploitation.
- **Synthetic Demonstration Data:** When publishing test fixtures or screenshots, use only synthetic data. Never commit real, sensitive operational coordinates or field staff PII.

### D. Code Quality & Security Standards
- **SQL Injection Prevention:** 100% of database interactions must use PDO prepared statements with bound parameters. Direct string interpolation in SQL queries is strictly prohibited.
- **XSS Mitigation:** All output rendered into HTML must be properly escaped using `htmlspecialchars(..., ENT_QUOTES, 'UTF-8')`.
- **Zero Secrets in Git:** Never commit `.env` files, production database passwords, private keys, or API tokens.

---

## 4. Running Verification Tests

Before publishing modifications to your fork, ensure that all automated test suites execute and pass cleanly:

```bash
# Run the complete test suite
php tests/run_all_tests.php
```

All new backend features or bugfixes should be accompanied by corresponding unit or integration test cases in the `tests/` directory following the naming convention:
`tests/test_<feature_name>.php`

---

## 5. Reporting Security Vulnerabilities

Because this repository is frozen and unmaintained, security vulnerabilities should be documented and patched directly within your independent forks. If you discover a critical issue affecting the public educational prototype, feel free to open a public issue or discussion in your downstream project.

---

## 6. License

By contributing to or modifying this codebase, you agree that your work will be licensed under the terms of the [MIT License](LICENSE).
