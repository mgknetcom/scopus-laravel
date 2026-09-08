# Scopus Laravel

Standalone Laravel 12/13 package for integrating Scopus author profiles, metrics, publications, and authorship suggestions. Filament 5 administration is optional.

The package provides:

- author metrics through the Author Retrieval API;
- complete publication discovery through paginated `AU-ID(...)` searches;
- detailed metadata retrieval by EID only for new works;
- normalized document types and a configurable pending-authorship workflow;
- idempotency by profile + EID, host matching by a contract, and safe repeated syncs;
- queued jobs with rate limiting and overlap protection;
- sync history and Filament administration resources;
- API credentials are read only from environment configuration.

## Installation

```bash
composer require mgknetcom/scopus-laravel
php artisan vendor:publish --tag=scopus-laravel-config
php artisan vendor:publish --tag=scopus-laravel-migrations
php artisan migrate
```

Configure the API credentials in the host `.env`:

```dotenv
SCOPUS_API_KEY=
SCOPUS_INSTITUTION_TOKEN=
SCOPUS_REQUESTS_PER_MINUTE=20
SCOPUS_QUEUE=default
SCOPUS_AUTO_IMPORT=false
SCOPUS_MAX_RETRY_AFTER=3600
SCOPUS_SYNC_RUNS_RETENTION_DAYS=90
SCOPUS_RESOLVED_RETENTION_DAYS=365
SCOPUS_RAW_PAYLOAD_RETENTION_DAYS=30
```

No credential is bundled in this package.

## Filament administration

The core integration does not require Filament. To use the optional administration resources, install Filament 5 in the host application:

```bash
composer require filament/filament:^5.7
```

Register the plugin in the host panel provider:

```php
use Mgknetcom\Scopus\ScopusPlugin;

return $panel
    // ...
    ->plugin(ScopusPlugin::make());
```

This adds:

- **Scopus profiles** — owner mapping, Author ID, metrics, status, manual metric sync and publication discovery;
- **Scopus suggestions** — searchable metadata, state filters, import, ignore and reopen actions.

The package deliberately does not prescribe host authorization. Add Filament policies for `ScopusProfile` and `ScopusSuggestion` when installing it in the target system.

## Host integration contracts

The package owns Scopus data, but it does not assume the names or schema of host `User` and `Publication` models. The host supplies two adapters.

### Match existing publications

The matcher should check DOI first, then the normalized exact title. Returning a reference marks the Scopus work as already present and avoids an unnecessary abstract request or import.

```php
namespace App\Scopus;

use App\Models\Publication;
use Mgknetcom\Scopus\Contracts\PublicationMatcher;
use Mgknetcom\Scopus\Data\PublicationReference;
use Mgknetcom\Scopus\Data\ScopusWork;
use Mgknetcom\Scopus\Models\ScopusProfile;

final class MatchHostPublication implements PublicationMatcher
{
    public function match(ScopusProfile $profile, ScopusWork $work): ?PublicationReference
    {
        $publication = Publication::query()
            ->where(function ($query) use ($work): void {
                if ($work->doi !== null) {
                    $query->where('doi', $work->doi)->orWhereRaw(
                        'lower(title) = ?',
                        [mb_strtolower($work->title)],
                    );
                } else {
                    $query->whereRaw('lower(title) = ?', [mb_strtolower($work->title)]);
                }
            })
            ->first();

        return $publication
            ? new PublicationReference(Publication::class, $publication->id, 'DOI or exact title')
            : null;
    }
}
```

Production code should use the host's existing DOI/title normalization helpers.

### Import as a pending authorship suggestion

The importer creates the host publication and links it to the profile owner with the host application's pending-authorship status. This lets the owner review the proposed authorship before the publication becomes visible through the host workflow.

```php
namespace App\Scopus;

use App\Enums\CoauthorshipStatus;
use App\Models\Publication;
use Mgknetcom\Scopus\Contracts\SuggestionImporter;
use Mgknetcom\Scopus\Data\PublicationReference;
use Mgknetcom\Scopus\Enums\ScopusDocumentType;
use Mgknetcom\Scopus\Models\ScopusSuggestion;

final class ImportPendingPublication implements SuggestionImporter
{
    public function import(ScopusSuggestion $suggestion): PublicationReference
    {
        $publication = Publication::query()->create([
            'title' => $suggestion->title,
            'authors' => $suggestion->authors ?? '',
            'type' => $this->hostType($suggestion->mapped_type),
            'year' => $suggestion->year,
            'isbn' => $suggestion->isbn,
            'issn' => $suggestion->issn,
            'pages' => $suggestion->pages,
            'publisher' => $suggestion->publisher,
            'conference' => $suggestion->conference,
            'doi' => $suggestion->doi,
            'source' => 'scopus',
            'indexed' => true,
            'in_scopus' => true,
            'citations_scopus' => $suggestion->citation_count,
        ]);

        $publication->authorsUsers()->attach($suggestion->profile->owner_id, [
            'status' => CoauthorshipStatus::Pending->value,
        ]);

        return new PublicationReference(Publication::class, $publication->id, 'Imported from Scopus');
    }

    private function hostType(ScopusDocumentType $type): string
    {
        return match ($type) {
            ScopusDocumentType::Article => 'article',
            ScopusDocumentType::ConferencePaper => 'conference_paper',
            ScopusDocumentType::Book => 'book',
            ScopusDocumentType::BookChapter => 'book_chapter',
            ScopusDocumentType::Other => 'other',
        };
    }
}
```

Adapt the type values to the host catalog. The imported publication must remain
outside public listings until the owner accepts the pending authorship; the exact
visibility mechanism belongs to the host application.

Bind both adapters in a host service provider or override the published configuration:

```php
$this->app->bind(
    \Mgknetcom\Scopus\Contracts\PublicationMatcher::class,
    \App\Scopus\MatchHostPublication::class,
);

$this->app->bind(
    \Mgknetcom\Scopus\Contracts\SuggestionImporter::class,
    \App\Scopus\ImportPendingPublication::class,
);
```

If bindings are configured through `config/scopus-laravel.php`, set the corresponding class names under `integration` instead. Do not register two competing bindings.

After both adapters are tested, set `SCOPUS_AUTO_IMPORT=true` to import every
newly discovered, unmatched Scopus work immediately as a pending authorship
suggestion. With the default `false`, it remains in the package's review queue
until an administrator uses **Import as suggestion**.

## Creating profiles

A profile can be created in Filament or in application code:

```php
$user->morphOne(\Mgknetcom\Scopus\Models\ScopusProfile::class, 'owner')->create([
    'author_id' => $user->scopus_id,
]);
```

The polymorphic owner allows the package to work with any authenticatable or domain model.

## Commands and scheduling

```bash
php artisan scopus:profiles:sync --stale=7 --limit=100
php artisan scopus:profiles:sync 15
php artisan scopus:works:discover --limit=100
php artisan scopus:works:discover 15
php artisan scopus:prune
php artisan scopus:prune --runs=90 --resolved=365 --raw=30 --dry-run
```

The commands enqueue one unique job per profile. A host application may schedule them:

```php
Schedule::command('scopus:profiles:sync --stale=7 --limit=100')->daily();
Schedule::command('scopus:works:discover --limit=100')->weekly();
Schedule::command('scopus:prune')->dailyAt('02:30');
```

Run a Laravel queue worker. The package uses the configured queue connection/name and a shared Scopus rate limiter.

Discovery stores a cursor after every completed result page. If Scopus returns a retryable error, the next queue attempt resumes at the last completed page. A `429` response with a `Retry-After` header releases the job for the requested delay, capped by `SCOPUS_MAX_RETRY_AFTER`.

The prune command removes completed synchronization runs and resolved suggestions beyond their retention periods, and clears older raw API payloads. Use `--dry-run` before changing retention values.

## Events

The package dispatches Laravel events that host applications may listen to without replacing package services:

- `ProfileSynced` after author metrics are stored;
- `WorkDiscovered` after a suggestion is created, matched, failed or refreshed;
- `SuggestionImported` after the host importer commits successfully;
- `SyncFailed` after a profile or discovery run fails.

## Feature overview

| Capability | Behavior |
|---|---|
| Author profiles | Links any host model to a Scopus Author ID through a polymorphic relationship |
| Author metrics | Synchronizes citation count, document count, h-index, cited-by count and coauthor count |
| Author identity | Stores preferred given name, surname, indexed name, initials and ORCID when returned by Scopus |
| Complete publication discovery | Searches by `AU-ID(authorId)` and follows every result page up to the configurable limit (5,000 by default) |
| Efficient detail retrieval | Requests the full abstract record by EID only when a work is not already matched or processed |
| Duplicate prevention | Uses profile + EID idempotency internally and lets the host match existing publications by DOI or normalized title |
| Metadata extraction | Maps title, authors, year, DOI, ISBN, ISSN, pages, publisher, conference, citations and source identifiers from Scopus responses |
| Document type normalization | Maps Scopus document subtypes to article, conference paper, book, book chapter or other |
| Review queue | Stores unmatched works as suggestions that administrators can inspect, import, ignore or reopen |
| Optional automatic import | Can pass every new unmatched work directly to the host importer while keeping it in pending-authorship state |
| Safe repeated synchronization | Re-running discovery updates known records without creating duplicate suggestions or imports |
| Resumable discovery | Persists page progress and resumes after retryable failures instead of restarting a large author search |
| Background processing | Dispatches unique queue jobs with configurable queue name, rate limiting and overlap protection |
| Operational history | Records synchronization runs, status, counters and errors for troubleshooting and administration |
| Data retention | Prunes old runs and resolved suggestions and clears raw payloads using configurable retention periods |
| Extension events | Emits events for successful profile sync, discovered works, imports and failures |
| Resilient API mapping | Handles optional and missing response fields and raises explicit API or configuration exceptions |
| Optional Filament administration | Provides profile and suggestion resources with filters, search and manual synchronization actions when Filament 5 is installed |
| Host independence | Integrates through matcher and importer contracts without requiring fixed user or publication schemas |

## API use

The package follows Elsevier's documented Scopus Search, Abstract Retrieval and Author Retrieval APIs. Search uses the STANDARD view (up to 200 records per page), and sends `X-ELS-APIKey`, optional `X-ELS-Insttoken`, and a unique `X-ELS-ReqId` as headers.

- [Scopus Search API](https://dev.elsevier.com/documentation/SCOPUSSearchAPI.wadl)
- [Abstract Retrieval API](https://dev.elsevier.com/documentation/AbstractRetrievalAPI.wadl)
- [Scopus API limits](https://dev.elsevier.com/api_key_settings.html)

When showing Scopus citation counts publicly, the host application is responsible for any required Scopus attribution and links under Elsevier's API terms.

## Testing

```bash
composer install
composer test
composer pint
composer stan
```

All HTTP tests use `Http::fake()` and must not call the live Elsevier API.
