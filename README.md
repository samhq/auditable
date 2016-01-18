# Auditable

[![Latest Version on Packagist][ico-version]][link-packagist]
[![Software License][ico-license]](LICENSE.md)
[![Build Status][ico-travis]][link-travis]
[![Coverage Status][ico-scrutinizer]][link-scrutinizer]
[![Quality Score][ico-code-quality]][link-code-quality]
[![Total Downloads][ico-downloads]][link-downloads]

> This package is developed based on [VentureCraft/Revisionable](https://github.com/venturecraft/revisionable)

Wouldn't it be nice to have a audit history for any model in your project, without having to do any work for it. By simply extending auditable from your model, you can instantly have just that, and be able to display a history similar to this:

* Feroj changed title from 'Something' to 'Something else'
* Sajid changed category from 'News' to 'Breaking news'
* Faheem changed category from 'Breaking news' to 'News'
* Samiul viewed this article at December 21, 2015 03:45PM.

So not only can you see a history of what happened, but who did what, so there's accountability.

Auditable is a laravel package that allows you to keep a audit history for your models without thinking. For some background and info, [see this article](http://www.chrisduell.com/blog/development/keeping-revisions-of-your-laravel-model-data/)

## Working with 3rd party Auth / Eloquent extensions

Auditable has support for Auth powered by
* [**Sentry by Cartalyst**](https://cartalyst.com/manual/sentry).
* [**Sentinel by Cartalyst**](https://cartalyst.com/manual/sentinel).

Auditable can also now be used [as a trait](#the-new-trait-based-implementation), so your models can continue to extend Eloquent, or any other class that extends Eloquent (like [Ardent](https://github.com/laravelbook/ardent)).

## Installation

Auditable is installable via [composer](http://getcomposer.org/doc/00-intro.md), the details are on [packagist, here.](https://packagist.org/packages/samveloper/auditable)

Add the following to the `require` section of your projects composer.json file:

```php
"samveloper/auditable": "0.*",
```

Run composer update to download the package

```
composer update
```

Finally, you'll also need to run migration on the package

```
php artisan migrate --package=samveloper/auditable
```

> If you're going to be migrating up and down completely a lot (using `migrate:refresh`), one thing you can do instead is to copy the migration file from the package to your `app/database` folder, and change the classname from `CreateAuditsTable` to something like `CreateAuditTable` (without the 's', otherwise you'll get an error saying there's a duplicate class)

> `cp vendor/samveloper/auditable/src/migrations/2016_01_18_060817_create_audits_table app/database/migrations/`

## Docs

* [Implementation](#intro)
* [More control](#control)
* [Format output](#formatoutput)
* [Load audit history](#loadhistory)
* [Display history](#display)

<a name="intro"></a>
## Implementation

### The new, trait based implementation

For any model that you want to keep a audit history for, include the auditable namespace and use the `AuditableTrait` in your model, e.g.,
If you are using another bootable trait the be sure to override the boot method in your model;

```php
namespace MyApp\Models;

class Article extends Eloquent {
    use \Samveloper\Auditable\AuditableTrait;

    public static function boot()
    {
        parent::boot();
    }
}
```

> Being a trait, auditable can now be used with the standard Eloquent model, or any class that extends Eloquent, like [Ardent](https://github.com/laravelbook/ardent) for example.

> Traits require PHP >= 5.4

### Legacy class based implementation

> The new trait based approach is backwards compatible with existing installations of Auditable. You can still use the below installation instructions, which essentially is extending a wrapper for the trait.

For any model that you want to keep a audit history for, include the auditable namespace and extend auditable instead of eloquent, e.g.,

```php
use Samveloper\Auditable\Auditable;

namespace MyApp\Models;

class Article extends Auditable { }
```

Note that it also works with namespaced models.

### Implementation notes

If needed, you can disable the auditing by setting `$auditEnabled` to false in your model. This can be handy if you want to temporarily disable auditing, or if you want to create your own base model that extends auditable, which all of your models extend, but you want to turn auditable off for certain models.

```php
namespace MyApp\Models;

class Article extends Eloquent {
    use Samveloper\Auditable\AuditableTrait;

    protected $auditEnabled = false;
}
```

You can also disable auditing after X many audits have been made by setting `$historyLimit` to the number of audits you want to keep before stopping audits.

```php
namespace MyApp\Models;

class Article extends Eloquent {
    use Samveloper\Auditable\AuditableTrait;

    protected $auditEnabled = true;
    protected $historyLimit = 500; //Stop tracking audits after 500 changes have been made.
}
```
In order to maintain a limit on history, but instead of stopping tracking audits if you want to remove old audits, you can accommodate that feature by setting `$auditCleanup`.

```php
namespace MyApp\Models;

class Article extends Eloquent {
    use Samveloper\Auditable\AuditableTrait;

    protected $auditEnabled = true;
    protected $auditCleanup = true; //Remove old audits (works only when used with $historyLimit)
    protected $historyLimit = 500; //Maintain a maximum of 500 changes at any point of time, while cleaning up old audits.
}
```

### Storing soft deletes

By default, if your model supports soft deletes, auditable will store this and any restores as updates on the model.

You can choose to ignore deletes and restores by adding `deleted_at` to your `$dontKeepAuditOf` array.

To better format the output for `deleted_at` entries, you can use the `isEmpty` formatter (see <a href="#format-output">Format output</a> for an example of this.)

<a name="control"></a>

### Storing creations
By default the creation of a new model is not stored as a audit.
Only subsequent changes to a model is stored.

If you want to store the creation as a audit you can override this behavior by setting `auditCreationsEnabled` to `true` by adding the following to your model:
```php
protected $auditCreationsEnabled = true;
```

## More control

No doubt, there'll be cases where you don't want to store a audit history only for certain fields of the model, this is supported in two different ways. In your model you can either specify which fields you explicitly want to track and all other fields are ignored:

```php
protected $keepAuditOf = array(
    'title'
);
```

Or, you can specify which fields you explicitly don't want to track. All other fields will be tracked.

```php
protected $dontKeepAuditOf = array(
    'category_id'
);
```

> The `$keepAuditOf` setting takes precendence over `$dontKeepAuditOf`

<a name="formatoutput"></a>
## Format output

> You can continue (and are encouraged to) use `eloquent accessors` in your model to set the
output of your values, see the [laravel docs for more information on accessors](http://laravel.com/docs/eloquent-mutators#accessors-and-mutators)
> The below documentation is therefor deprecated

In cases where you want to have control over the format of the output of the values, for example a boolean field, you can set them in the `$auditFormattedFields` array in your model. e.g.,

```php
protected $auditFormattedFields = array(
    'title'  => 'string:<strong>%s</strong>',
    'public' => 'boolean:No|Yes',
    'modified' => 'datetime:m/d/Y g:i A',
    'deleted_at' => 'isEmpty:Active|Deleted'
);
```

You can also override the field name output using the `$auditFormattedFieldNames` array in your model, e.g.,

```php
protected $auditFormattedFieldNames = array(
    'title' => 'Title',
    'small_name' => 'Nickname',
    'deleted_at' => 'Deleted At'
);
```

This comes into play when you output the audit field name using `$audit->fieldName()`

### String
To format a string, simply prefix the value with `string:` and be sure to include `%s` (this is where the actual value will appear in the formatted response), e.g.,

```
string:<strong>%s</strong>
```

### Boolean
Booleans by default will display as a 0 or a 1, which is pretty bland and won't mean much to the end user, so this formatter can be used to output something a bit nicer. Prefix the value with `boolean:` and then add your false and true options separated by a pipe, e.g.,

```
boolean:No|Yes
```

### DateTime
DateTime by default will display as Y-m-d H:i:s. Prefix the value with `datetime:` and then add your datetime format, e.g.,

```
datetime:m/d/Y g:i A
```

### Is Empty
This piggy backs off boolean, but instead of testing for a true or false value, it checks if the value is either null or an empty string.

```
isEmpty:No|Yes
```

This can also accept `%s` if you'd like to output the value, something like the following will display 'Nothing' if the value is empty, or the actual value if something exists:

```
isEmpty:Nothing|%s
```

<a name="loadhistory"></a>
## Load audit history

To load the audit history for a given model, simply call the `auditHistory` method on that model, e.g.,

```php
$article = Article::find($id);
$history = $article->auditHistory;
```

<a name="display"></a>
## Displaying history

For the most part, the audit history will hold enough information to directly output a change history, however in the cases where a foreign key is updated we need to be able to do some mapping and display something nicer than `plan_id changed from 3 to 1`.

To help with this, there's a few helper methods to display more insightful information, so you can display something like `Chris changed plan from bronze to gold`.

The above would be the result from this:

```php
@foreach($account->auditHistory as $history )
    <li>{{ $history->userResponsible()->first_name }} changed {{ $history->fieldName() }} from {{ $history->oldValue() }} to {{ $history->newValue() }}</li>
@endforeach
```

If you have enabled audits of creations as well you can display it like this:
```php
@foreach($resource->auditHistory as $history)
  @if($history->key == 'created_at' && !$history->old_value)
    <li>{{ $history->userResponsible()->first_name }} created this resource at {{ $history->newValue() }}</li>
  @else
    <li>{{ $history->userResponsible()->first_name }} changed {{ $history->fieldName() }} from {{ $history->oldValue() }} to {{ $history->newValue() }}</li>
  @endif
@endforeach
```

### userResponsible()

Returns the User that was responsible for making the audit. A user model is returned, or null if there was no user recorded.

The user model that is loaded depends on what you have set in your `config/auth.php` file for the `model` variable.

### fieldName()

Returns the name of the field that was updated, if the field that was updated was a foreign key (at this stage, it simply looks to see if the field has the suffix of `_id`) then the text before `_id` is returned. e.g., if the field was `plan_id`, then `plan` would be returned.

> Remember from above, that you can override the output of a field name with the `$auditFormattedFieldNames` array in your model.

### identifiableName()

This is used when the value (old or new) is the id of a foreign key relationship.

By default, it simply returns the ID of the model that was updated. It is up to you to override this method in your own models to return something meaningful. e.g.,

```php
use Samveloper\Auditable\Auditable;

class Article extends Auditable
{
    public function identifiableName()
    {
        return $this->title;
    }
}
```

### oldValue() and newValue()

Get the value of the model before or after the update. If it was a foreign key, identifiableName() is called.

### Unknown or invalid foreign keys as audits
In cases where the old or new version of a value is a foreign key that no longer exists, or indeed was null, there are two variables that you can set in your model to control the output in these situations:

```php
protected $auditNullString = 'nothing';
protected $auditUnknownString = 'unknown';
```

### disableAuditField()
Sometimes temporarily disabling a auditable field can come in handy, if you want to be able to save an update however don't need to keep a record of the changes.

```php
$object->disableAuditField('title'); // Disables title
```

or:

```php
$object->disableAuditField(array('title', 'content')); // Disables title and content
```

## Change log

Please see [CHANGELOG](CHANGELOG.md) for more information what has changed recently.

## License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.

[ico-version]: https://img.shields.io/packagist/v/samveloper/auditable.svg?style=flat-square
[ico-license]: https://img.shields.io/badge/license-MIT-brightgreen.svg?style=flat-square
[ico-travis]: https://img.shields.io/travis/samveloper/auditable/master.svg?style=flat-square
[ico-scrutinizer]: https://img.shields.io/scrutinizer/coverage/g/samveloper/auditable.svg?style=flat-square
[ico-code-quality]: https://img.shields.io/scrutinizer/g/samveloper/auditable.svg?style=flat-square
[ico-downloads]: https://img.shields.io/packagist/dt/samveloper/auditable.svg?style=flat-square

[link-packagist]: https://packagist.org/packages/samveloper/auditable
[link-travis]: https://travis-ci.org/samveloper/auditable
[link-scrutinizer]: https://scrutinizer-ci.com/g/samveloper/auditable/code-structure
[link-code-quality]: https://scrutinizer-ci.com/g/samveloper/auditable
[link-downloads]: https://packagist.org/packages/samveloper/auditable
[link-author]: https://github.com/samveloper
[link-contributors]: ../../contributors
