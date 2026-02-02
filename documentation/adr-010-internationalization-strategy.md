# [ADR-010] Internationalization (i18n) Strategy

* **Status:** `Accepted`
* **Date:** 2026-02-02
* **Authors:** Engineering Team
* **Deciders:** Head of Engineering
* **Technical Context:** PHP 8.2+, Symfony 7.3 Translation Component

## 1. Context and Problem Statement

**The Pain Point:** CLI tools often hardcode English text, making them inaccessible to non-English speakers:
- **Hardcoded Strings:** Error messages, help text, and prompts in English only
- **No Localization:** Can't adapt to user's language preference
- **Poor UX:** Non-English speakers struggle with English-only interfaces
- **Maintenance:** Hard to update messages across the codebase

**The Goal:** Implement an internationalization system that:
- Supports multiple languages
- Allows users to configure their preferred language
- Centralizes all user-facing text
- Makes it easy to add new languages
- Works in both PHAR and development environments

## 2. Decision Drivers & Constraints

* **User Experience:** Support multiple languages for better accessibility
* **Maintainability:** Centralize all user-facing text
* **Symfony Integration:** Use Symfony Translation component
* **PHAR Compatibility:** Must work when compiled to PHAR
* **Performance:** Translation loading should be fast
* **Extensibility:** Easy to add new languages

## 3. Considered Options

* **Option 1:** Hardcode English strings
  * Pros: Simple, no dependencies
  * Cons: No localization, poor UX, hard to maintain

* **Option 2:** Use Symfony Translation component with YAML files (Chosen)
  * Pros: Standard Symfony approach, YAML is readable, supports pluralization
  * Cons: Requires Symfony Translation dependency

* **Option 3:** Use gettext (PO files)
  * Pros: Industry standard, good tooling
  * Cons: More complex, requires gettext extension, less readable

* **Option 4:** Use JSON translation files
  * Pros: Simple, no dependencies
  * Cons: Less features than Symfony Translation, no pluralization support

## 4. Decision Outcome

**Chosen Option:** `Option 2 - Symfony Translation Component with YAML Files`

**Justification:**
We chose Symfony Translation because:
1. **Symfony Native:** Already using Symfony, translation component is standard
2. **YAML Format:** Human-readable, easy to edit and review
3. **Feature Rich:** Supports pluralization, parameters, and nested keys
4. **PHAR Compatible:** Works in both PHAR and development environments
5. **Maintainable:** Centralized translation files, easy to update
6. **Extensible:** Easy to add new languages by creating new YAML files

The system consists of:
- **TranslationService:** Wraps Symfony Translator, handles locale detection
- **YAML Files:** `src/resources/translations/messages.{locale}.yaml`
- **Locale Configuration:** User's language preference stored in config
- **Fallback:** Defaults to English if translation missing

## 5. Consequences (Trade-offs)

| Aspect | Result (Positive / Negative / Neutral) |
| --- | --- |
| **User Experience** | *(+) Supports multiple languages, better accessibility* |
| **Maintainability** | *(+) Centralized translations, easy to update* |
| **Extensibility** | *(+) Easy to add new languages* |
| **Performance** | *(Neutral) Translation loading is fast, cached by Symfony* |
| **Dependency** | *(+) Adds Symfony Translation dependency, but already using Symfony* |
| **Complexity** | *(-) Requires understanding translation keys and structure* |
| **PHAR Compatibility** | *(+) Works in both PHAR and development* |

## 6. Implementation Plan

* [x] Create `TranslationService` wrapper around Symfony Translator
* [x] Create translation YAML files for each language
* [x] Implement locale detection from config
* [x] Add language selection to `config:init`
* [x] Update all user-facing strings to use translation keys
* [x] Handle PHAR path resolution for translation files
* [x] Document translation key structure
* [x] Create translation file template

---

### Implementation Details

**Translation Service:**

```php
class TranslationService
{
    public function __construct(
        private readonly string $locale,
        private readonly string $translationsPath
    ) {
        $this->translator = new Translator($locale);
        $this->translator->addLoader('yaml', new YamlFileLoader());
        $this->translator->addResource('yaml', $translationsPath . "/messages.{$locale}.yaml", $locale);
    }
    
    public function trans(string $key, array $parameters = []): string
    {
        return $this->translator->trans($key, $parameters, 'messages', $this->locale);
    }
}
```

**Translation File Structure:**

```yaml
# messages.en.yaml
help:
  title: "Available Commands"
  command_config_init: "Interactive wizard to set up Jira & Git connection details"
  
config:
  init:
    wizard:
      title: "Configuration Wizard"
      description: "This wizard will help you configure stud-cli"
```

**Usage:**

```php
$translator = _get_translation_service();
$message = $translator->trans('help.title');
$message = $translator->trans('config.init.wizard.title', ['path' => $configPath]);
```

**Locale Detection:**

```php
function _get_translation_service(): TranslationService
{
    $locale = 'en'; // Default
    $configPath = _get_config_path();
    
    if (file_exists($configPath)) {
        $config = _get_config();
        $locale = $config['LANGUAGE'] ?? 'en';
    }
    
    $translationsPath = __DIR__ . '/src/resources/translations';
    if (class_exists('Phar') && \Phar::running(false)) {
        $translationsPath = 'phar://' . \Phar::running(false) . '/src/resources/translations';
    }
    
    return new TranslationService($locale, $translationsPath);
}
```

**Supported Languages:**
- English (en) - Default
- French (fr)
- Spanish (es)
- Dutch (nl)
- Russian (ru)
- Greek (el)
- Afrikaans (af)
- Vietnamese (vi)

**Translation Key Convention:**
- Use dot notation for nesting: `category.subcategory.key`
- Group by feature: `config.init.*`, `help.*`, `migration.*`
- Use descriptive keys: `command_config_init` not `cmd1`

---

### Pro-Tip for Symfony 7.4 ADRs

This decision follows **Symfony Best Practices** for internationalization. Symfony 7.4's Translation component uses **YAML over XML** for configuration (more readable) and supports **Pluralization and Parameters** out of the box. The `TranslationService` wrapper provides a clean abstraction while leveraging Symfony's translation capabilities, aligning with Symfony 7.4's preference for **Service Composition** over direct framework usage.
