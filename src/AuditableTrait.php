<?php

namespace Samveloper\Auditable;

/**
 * Class AuditableTrait
 * @package Samveloper\Auditable
 */
trait AuditableTrait
{
    /**
     * @var array
     */
    private $originalData = array();

    /**
     * @var array
     */
    private $updatedData = array();

    /**
     * @var boolean
     */
    private $updating = false;

    /**
     * @var array
     */
    private $dontKeep = array();

    /**
     * @var array
     */
    private $doKeep = array();

    /**
     * Keeps the list of values that have been updated
     *
     * @var array
     */
    protected $dirtyData = array();

    /**
     * Ensure that the bootAuditableTrait is called only
     * if the current installation is a laravel 4 installation
     * Laravel 5 will call bootAuditableTrait() automatically
     */
    public static function boot()
    {
        parent::boot();

        if (!method_exists(get_called_class(), 'bootTraits')) {
            static::bootAuditableTrait();
        }
    }

    /**
     * Create the event listeners for the saving and saved events
     * This lets us save audits whenever a save is made, no matter the
     * http method.
     *
     */
    public static function bootAuditableTrait()
    {
        static::showed(function ($model) {
            $model->postShow();
        });

        static::saving(function ($model) {
            $model->preSave();
        });

        static::saved(function ($model) {
            $model->postSave();
        });

        static::created(function($model){
            $model->postCreate();
        });

        static::deleted(function ($model) {
            $model->preSave();
            $model->postDelete();
        });
    }

    /**
     * @return mixed
     */
    public function auditHistory()
    {
        return $this->morphMany('\Samveloper\Auditable\Audit', 'auditable');
    }

    /**
     * Generates a list of the last $limit audits made to any objects of the class it is being called from.
     *
     * @param int $limit
     * @param string $order
     * @return mixed
     */
    public static function classAuditHistory($limit = 100, $order = 'desc')
    {
        return \Samveloper\Auditable\Audit::where('auditable_type', get_called_class())
            ->orderBy('updated_at', $order)->limit($limit)->get();
    }

    /**
    * Invoked before a model is saved. Return false to abort the operation.
    *
    * @return bool
    */
    public function preSave()
    {
        if (!isset($this->auditEnabled) || $this->auditEnabled) {
            // if there's no auditEnabled. Or if there is, if it's true

            $this->originalData = $this->original;
            $this->updatedData = $this->attributes;

            // we can only safely compare basic items,
            // so for now we drop any object based items, like DateTime
            foreach ($this->updatedData as $key => $val) {
                if (gettype($val) == 'object' && !method_exists($val, '__toString')) {
                    unset($this->originalData[$key]);
                    unset($this->updatedData[$key]);
                    array_push($this->dontKeep, $key);
                }
            }

            // the below is ugly, for sure, but it's required so we can save the standard model
            // then use the keep / dontkeep values for later, in the isAuditable method
            $this->dontKeep = isset($this->dontKeepAuditOf) ?
                $this->dontKeepAuditOf + $this->dontKeep
                : $this->dontKeep;

            $this->doKeep = isset($this->keepAuditOf) ?
                $this->keepAuditOf + $this->doKeep
                : $this->doKeep;

            unset($this->attributes['dontKeepAuditOf']);
            unset($this->attributes['keepAuditOf']);

            $this->dirtyData = $this->getDirty();
            $this->updating = $this->exists;
        }
    }


    /**
     * Called after a model is successfully saved.
     *
     * @return void
     */
    public function postSave()
    {
        if (isset($this->historyLimit) && $this->auditHistory()->count() >= $this->historyLimit) {
            $LimitReached = true;
        } else {
            $LimitReached = false;
        }
        if (isset($this->auditCleanup)){
            $AuditCleanup=$this->auditCleanup;
        }else{
            $AuditCleanup=false;
        }

        // check if the model already exists
        if (((!isset($this->auditEnabled) || $this->auditEnabled) && $this->updating) && (!$LimitReached || $AuditCleanup)) {
            // if it does, it means we're updating

            $changes_to_record = $this->changedAuditableFields();

            $audits = array();

            foreach ($changes_to_record as $key => $change) {
                $audits[] = array(
                    'auditable_type' => get_class($this),
                    'auditable_id' => $this->getKey(),
                    'key' => $key,
                    'old_value' => array_get($this->originalData, $key),
                    'new_value' => $this->updatedData[$key],
                    'user_id' => $this->getUserId(),
                    'created_at' => new \DateTime(),
                    'updated_at' => new \DateTime(),
                );
            }

            if (count($audits) > 0) {
                if($LimitReached && $AuditCleanup){
                    $toDelete = $this->auditHistory()->orderBy('id','asc')->limit(count($audits))->get();
                    foreach($toDelete as $delete){
                        $delete->delete();
                    }
                }
                $audit = new Audit;
                \DB::table($audit->getTable())->insert($audits);
            }
        }
    }

    /**
     * Called after a model is successfully showed.
     *
     * @return void
     */
    public function postShow()
    {
        if ((!isset($this->auditEnabled) || $this->auditEnabled))
        {
            $audits[] = array(
                'auditable_type' => get_class($this),
                'auditable_id' => $this->getKey(),
                'key' => 'showed_at',
                'old_value' => null,
                'new_value' => $this->created_at,
                'user_id' => $this->getUserId(),
                'created_at' => new \DateTime(),
                'updated_at' => new \DateTime(),
            );

            $audit = new Audit;
            \DB::table($audit->getTable())->insert($audits);
        }
    }

    /**
    * Called after record successfully created
    */
    public function postCreate()
    {

        // Check if we should store creations in our audit history
        // Set this value to true in your model if you want to
        if(empty($this->auditCreationsEnabled))
        {
            // We should not store creations.
            return false;
        }

        if ((!isset($this->auditEnabled) || $this->auditEnabled))
        {
            $audits[] = array(
                'auditable_type' => get_class($this),
                'auditable_id' => $this->getKey(),
                'key' => 'created_at',
                'old_value' => null,
                'new_value' => $this->created_at,
                'user_id' => $this->getUserId(),
                'created_at' => new \DateTime(),
                'updated_at' => new \DateTime(),
            );

            $audit = new Audit;
            \DB::table($audit->getTable())->insert($audits);

        }


    }

    /**
     * If softdeletes are enabled, store the deleted time
     */
    public function postDelete()
    {
        if ((!isset($this->auditEnabled) || $this->auditEnabled)
            && $this->isSoftDelete()
            && $this->isAuditable('deleted_at')
        ) {
            $audits[] = array(
                'auditable_type' => get_class($this),
                'auditable_id' => $this->getKey(),
                'key' => 'deleted_at',
                'old_value' => null,
                'new_value' => $this->deleted_at,
                'user_id' => $this->getUserId(),
                'created_at' => new \DateTime(),
                'updated_at' => new \DateTime(),
            );
            $audit = new \Samveloper\Auditable\Audit;
            \DB::table($audit->getTable())->insert($audits);
        }
    }

    /**
     * Attempt to find the user id of the currently logged in user
     * Supports Cartalyst Sentry/Sentinel based authentication, as well as stock Auth
     **/
    public function getUserId()
    {
        try {
            if (class_exists($class = '\SleepingOwl\AdminAuth\Facades\AdminAuth')
                || class_exists($class = '\Cartalyst\Sentry\Facades\Laravel\Sentry')
                || class_exists($class = '\Cartalyst\Sentinel\Laravel\Facades\Sentinel')
            ) {
                return ($class::check()) ? $class::getUser()->id : null;
            } elseif (\Auth::check()) {
                return \Auth::user()->getAuthIdentifier();
            }
        } catch (\Exception $e) {
            return null;
        }

        return null;
    }

    /**
     * Get all of the changes that have been made, that are also supposed
     * to have their changes recorded
     *
     * @return array fields with new data, that should be recorded
     */
    private function changedAuditableFields()
    {
        $changes_to_record = array();
        foreach ($this->dirtyData as $key => $value) {
            // check that the field is auditable, and double check
            // that it's actually new data in case dirty is, well, clean
            if ($this->isAuditable($key) && !is_array($value)) {
                if (!isset($this->originalData[$key]) || $this->originalData[$key] != $this->updatedData[$key]) {
                    $changes_to_record[$key] = $value;
                }
            } else {
                // we don't need these any more, and they could
                // contain a lot of data, so lets trash them.
                unset($this->updatedData[$key]);
                unset($this->originalData[$key]);
            }
        }

        return $changes_to_record;
    }

    /**
     * Check if this field should have a audit kept
     *
     * @param string $key
     *
     * @return bool
     */
    private function isAuditable($key)
    {

        // If the field is explicitly auditable, then return true.
        // If it's explicitly not auditable, return false.
        // Otherwise, if neither condition is met, only return true if
        // we aren't specifying auditable fields.
        if (isset($this->doKeep) && in_array($key, $this->doKeep)) {
            return true;
        }
        if (isset($this->dontKeep) && in_array($key, $this->dontKeep)) {
            return false;
        }

        return empty($this->doKeep);
    }

    /**
     * Check if soft deletes are currently enabled on this model
     *
     * @return bool
     */
    private function isSoftDelete()
    {
        // check flag variable used in laravel 4.2+
        if (isset($this->forceDeleting)) {
            return !$this->forceDeleting;
        }

        // otherwise, look for flag used in older versions
        if (isset($this->softDelete)) {
            return $this->softDelete;
        }

        return false;
    }

    /**
     * @return mixed
     */
    public function getAuditFormattedFields()
    {
        return $this->auditFormattedFields;
    }

    /**
     * @return mixed
     */
    public function getAuditFormattedFieldNames()
    {
        return $this->auditFormattedFieldNames;
    }

    /**
     * Identifiable Name
     * When displaying audit history, when a foreign key is updated
     * instead of displaying the ID, you can choose to display a string
     * of your choice, just override this method in your model
     * By default, it will fall back to the models ID.
     *
     * @return string an identifying name for the model
     */
    public function identifiableName()
    {
        return $this->getKey();
    }

    /**
     * Audit Unknown String
     * When displaying audit history, when a foreign key is updated
     * instead of displaying the ID, you can choose to display a string
     * of your choice, just override this method in your model
     * By default, it will fall back to the models ID.
     *
     * @return string an identifying name for the model
     */
    public function getAuditNullString()
    {
        return isset($this->auditNullString) ? $this->auditNullString : 'nothing';
    }

    /**
     * No audit string
     * When displaying audit history, if the audits value
     * cant be figured out, this is used instead.
     * It can be overridden.
     *
     * @return string an identifying name for the model
     */
    public function getAuditUnknownString()
    {
        return isset($this->auditUnknownString) ? $this->auditUnknownString : 'unknown';
    }

    /**
     * Disable a auditable field temporarily
     * Need to do the adding to array longhanded, as there's a
     * PHP bug https://bugs.php.net/bug.php?id=42030
     *
     * @param mixed $field
     *
     * @return void
     */
    public function disableAuditField($field)
    {
        if (!isset($this->dontKeepAuditOf)) {
            $this->dontKeepAuditOf = array();
        }
        if (is_array($field)) {
            foreach ($field as $one_field) {
                $this->disableAuditField($one_field);
            }
        } else {
            $donts = $this->dontKeepAuditOf;
            $donts[] = $field;
            $this->dontKeepAuditOf = $donts;
            unset($donts);
        }
    }

    /**
     * Register a showed model event with the dispatcher.
     *
     * @param  \Closure|string  $callback
     * @param  int  $priority
     * @return void
     */
    public static function showed($callback, $priority = 0)
    {
        static::registerModelEvent('showed', $callback, $priority);
    }

    /**
     * Overriding the find method to fire a 'showed' event.
     *
     * @param $id
     * @param array $columns
     * @return null
     */
    public static function find($id, $columns = ['*'])
    {
        if (! is_null($instance = (new static)->newQueryWithoutScopes()->find($id, $columns))) {
            $instance->fireModelEvent('showed', false);
            return $instance;
        }

        return null;
    }

}
