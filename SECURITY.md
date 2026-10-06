# Security Policy

## Reporting a vulnerability

Please do not publish credentials, API keys, production URLs, or exploit details in a public issue.

Use GitHub's private security reporting / Security Advisories feature when it is available for the repository.

When reporting a vulnerability, include:

- affected file or component;
- deployment context;
- reproduction steps;
- expected and observed behavior;
- impact assessment.

## Secrets

Never place real API keys, Turnstile secrets, passwords, or private configuration in Git.

Use `azuracast-api-proxy.env.example` as a template and keep the real configuration outside the repository and outside the public web document root.

## Supported security assumptions

This project is intended for HTTPS deployments.

The AzuraCast API key is a server-side secret. It must never be moved into `js/` or `index.html`.

The dashboard may expose station analytics and approximate listener location information. Operators are responsible for ensuring that the deployment complies with applicable privacy requirements.
