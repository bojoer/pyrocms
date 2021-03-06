<?php namespace Pyro\Module\Streams_core;

/**
 * PyroStreams Core Fields Library
 *
 * Handles forms and other field form logic.
 *
 * @package        PyroCMS\Core\Modules\Streams Core\Libraries
 * @author         Parse19
 * @copyright      Copyright (c) 2011 - 2012, Parse19
 * @license        http://parse19.com/pyrostreams/docs/license
 * @link           http://parse19.com/pyrostreams
 */
class EntryFormBuilder extends AbstractUi
{
    /**
     * Field type events run
     *
     * @var array
     */
    protected $fieldTypeEventsRun = array();

    /**
     * Construct with the entry object optional
     *
     * @param object $entry
     */
    public function __construct(EntryModel $entry = null)
    {
        $attributes = array();

        if ($attributes['entry'] = $entry) {

            $attributes['assignments'] = $entry->getAssignments();

            $attributes['method'] = $entry->getKey() ? 'edit' : 'new';
        }

        ci()->load->helper(array('form', 'url'));

        parent::__construct($attributes);
    }

    /**
     * Run Field Events
     *
     * Runs all the event() functions for some
     * stream fields. The event() functions usually
     * have field asset loads.
     *
     * @access    public
     *
     * @param    obj - stream fields
     * @param     [array - skips]
     *
     * @return    array
     */
    public static function runFieldSetupEvents($current_field = null)
    {
        $types = FieldTypeManager::getAllTypes();

        foreach ($types as $type) {
            if (method_exists($type, 'field_setup_event')) {
                $type->field_setup_event($current_field);
            }
        }
    }

    /**
     * Boot
     *
     * @return void
     */
    public function boot()
    {
        parent::boot();

        // -------------------------------------
        // Set default properties
        // -------------------------------------
        $this->emailNotifications       = null;
        $this->fieldTypeEventsRun       = array();
        $this->fieldTypePublicEventsRun = array();
        $this->messages                 = array(
            'success' => 'lang:streams:' . $this->method . '_entry_success',
            'error'   => 'lang:streams:' . $this->method . '_entry_error'
        );
        $this->returnValidationRules    = false;
        $this->recaptcha                = false;
        $this->redirect                 = current_url();
        $this->outputRequired           = '<span>*</span>';
        $this->values                   = array();
    }

    /**
     * Build the form validation rules and the actual output.
     *
     * Based on the type of application we need it for, it will
     * either return a full form or an array of elements.
     *
     * @access    public
     *
     * @param    obj
     * @param    string
     * @param    mixed - false or row object
     * @param    bool  - is this a plugin call?
     * @param    bool  - are we using reCAPTCHA?
     * @param    array - all the skips
     * @param    array - extra data:
     * @param    array - default values: Only used during new method.
     *
     * - email_notifications
     * - return
     * - messageSuccess
     * - messageError
     * - errorStart
     * - errorEnd
     * - outputRequired
     *
     * @return    array - fields
     */
    public function buildForm()
    {
        // -------------------------------------
        // Get Stream Fields
        // -------------------------------------
        if ($this->assignments->isEmpty()) {
            return null;
        }

        // -------------------------------------
        // Set Validation Rules
        // -------------------------------------
        // We will only set the rules if the
        // data has been posted. This works hand
        // in hand with checking the $_POST array
        // as well as the data validation when
        // we decide what to do with the form.
        // -------------------------------------
        ci()->form_validation->reset_validation();
        $validation_rules = $this->setRules();
        ci()->form_validation->set_rules($validation_rules);


        // -------------------------------------
        // Set Error Delimns
        // -------------------------------------

        ci()->form_validation->set_error_delimiters($this->errorStart, $this->errorEnd);

        // -------------------------------------
        // Set reCAPTCHA
        // -------------------------------------

        if ($this->recaptcha and $_POST) {
            ci()->config->load('streams_core / recaptcha');
            ci()->load->library('streams_core / Recaptcha');

            ci()->form_validation->setRules(
                'recaptcha_response_field',
                'lang:recaptcha_field_name',
                'required | check_recaptcha'
            );
        }

        // -------------------------------------
        // Set Values
        // -------------------------------------

        //$stream_fields, $row, $this->method, $skips, $defaults, $this->key_check

        $values = $this->getFormValues($this->assignments, $this->entry);

        // -------------------------------------
        // Validation
        // -------------------------------------

        $result_id = '';

        if ($_POST and $this->enableSave) {
            //if (empty($validation_rules)) {
            if (!$this->entry->getKey()) { // new
                // ci()->row_m->insert_entry($_POST, $stream_fields, $stream, $skips);
                if (!$this->entry->preSave($this->skips)) {
                    ci()->session->set_flashdata('notice', lang_label($this->messageError));
                } else {
                    $this->result = $this->entry;

                    // -------------------------------------
                    // Send Emails
                    // -------------------------------------

                    if (isset($this->emailNotifications) and $this->emailNotifications) {
                        foreach ($this->emailNotifications as $notify) {
                            $this->sendEmail($notify, $result_id, !$this->entry->getKey(), $stream);
                        }
                    }

                    // -------------------------------------

                    ci()->session->set_flashdata('success', lang_label($this->messageSuccess));
                }
            } else { // edit
                if (!$this->entry->preSave($this->skips) and $this->messageError) {
                    ci()->session->set_flashdata('notice', lang_label($this->messageError));
                } else {
                    $this->result = $this->entry;

                    // -------------------------------------
                    // Send Emails
                    // -------------------------------------

                    if (isset($this->emailNotifications) and is_array($this->emailNotifications)) {
                        foreach ($this->emailNotifications as $notify) {
                            $this->sendEmail($notify, $result_id, $this->method = 'update', $stream);
                        }
                    }

                    // -------------------------------------
                    ci()->session->set_flashdata('success', lang_label($this->messageSuccess));
                }
            }
            //}
        }

        // -------------------------------------
        // Run Type Events
        // -------------------------------------
        // No matter what, we'll need these
        // events run for field type assets
        // and other processes.
        // -------------------------------------

        $fields = $this->buildFields();

        // -------------------------------------
        // Set Fields & Return Them
        // -------------------------------------

        // $stream_fields, $values, $row, $this->method, $skips, $extra['required']
        return $fields;
    }

    /**
     * Run Field Setup Event Functions
     *
     * This allows field types to add custom CSS/JS
     * to the field setup (edit/delete screen).
     *
     * @access    public
     *
     * @param     [obj - stream]
     * @param     [string - method - new or edit]
     * @param     [obj or null (for new fields) - field]
     *
     * @return
     */
    public function setRules()
    {
        if ($this->assignments->isEmpty()) {
            return array();
        }

        $validation_rules = array();

        // -------------------------------------
        // Loop through and set the rules
        // -------------------------------------
        foreach ($this->assignments as $assignment) {
            if (!in_array($assignment->field->field_slug, $this->getSkips(array()))) {

                $rules = array();

                $stream = $this->entry->getStream();

                // If we don't have the type, then no need to go on.
                if (!$type = $assignment->getType()) {
                    continue;
                }

                // -------------------------------------
                // Pre Validation Event
                // -------------------------------------

                if (method_exists($type, 'pre_validation_compile')) {
                    $type->pre_validation_compile($assignment);
                }

                // -------------------------------------
                // Set required if necessary
                // -------------------------------------

                if ($assignment->is_required == true) {
                    if (isset($type->input_is_file) && $type->input_is_file === true) {
                        $rules[] = 'streams_file_required[' . $assignment->field_slug . ']';
                    } else {
                        $rules[] = 'required';
                    }
                }

                // -------------------------------------
                // Validation Function
                // -------------------------------------
                // We are using a generic streams validation
                // function to use a validate() function
                // in the field type itself.
                // -------------------------------------

                /*if (method_exists($type, 'validate')) {
                    $rules[] = "streams_field_validation[{$assignment->getKey()}:{$this->method}]";
                }*/

                // -------------------------------------
                // Set unique if necessary
                // -------------------------------------

                /*if ($assignment->is_unique == true) {
                    $rules[] = 'streams_unique['.$assignment->field_slug.':'.$this->method.':'.$assignment->stream_id.':'.$this->entry->getKey().']';
                }*/

                // -------------------------------------
                // Set extra validation
                // -------------------------------------

                /*if (isset($type->extra_validation)) {
                    if (is_string($type->extra_validation)) {
                        $extra_rules = explode('|', $type->extra_validation);
                        $rules = array_merge($rules, $extra_rules);
                        unset($extra_rules);
                    } elseif (is_array($type->extra_validation)) {
                        $rules = array_merge($rules, $type->extra_validation);
                    }
                }*/

                // -------------------------------------
                // Remove duplicate rule values
                // -------------------------------------

                $rules = array_unique($rules);

                // -------------------------------------
                // Add to validation rules array
                // and unset $rules
                // -------------------------------------

                if (empty($rules)) {
                    continue;
                }

                $validation_rules[] = array(
                    'field' => $stream->stream_namespace . '-' . $stream->stream_slug . '-' . $assignment->field->field_slug,
                    'label' => lang_label($assignment->field_name),
                    'rules' => implode('|', $rules)
                );

                unset($rules);
            }
        }

        // -------------------------------------
        // Set the rules or return them
        // -------------------------------------

        return $validation_rules;
    }

    public static function getFormValues($fields = array(), EntryModel $entry = null, $skips = array())
    {
        if (empty($fields) or !$entry) {
            return array();
        }

        $values = array();

        foreach ($fields as $field) {
            if (!in_array($field->field_slug, $skips)) {
                if ($type = $field->getType($entry) and !$type->alt_process) {
                    $entry->{$type->getColumnName()} = $values[$type->getColumnName()] = $type->getFormValue();
                }
            }
        }

        return $values;
    }


    public function sendEmail()
    {
        extract($notify);

        // We need a notify to and a template, or
        // else we can't do anything. Everything else
        // can be substituted with a default value.
        if (!isset($notify) and !$notify) {
            return null;
        }
        if (!isset($template) and !$template) {
            return null;
        }

        // -------------------------------------
        // Get e-mails. Forget if there are none
        // -------------------------------------

        $emails = explode('|', $notify);

        if (empty($emails)) {
            return null;
        }

        // For each email, we can have an email value, or
        // we take it from the form's post values.
        foreach ($emails as $key => $piece) {
            $emails[$key] = $this->processEmailAddress($piece);
        }

        // -------------------------------------
        // Parse Email Template
        // -------------------------------------
        // Get the email template from
        // the database and create some
        // special vars to pass off.
        // -------------------------------------

        $layout = ci()->db
            ->limit(1)
            ->where('slug', $template)
            ->get('email_templates')
            ->row();

        if (!$layout) {
            return null;
        }

        // -------------------------------------
        // Get some basic sender data
        // -------------------------------------
        // These are for use in the email template.
        // -------------------------------------

        ci()->load->library('user_agent');

        $data = array(
            'sender_ip'    => ci()->input->ip_address(),
            'sender_os'    => ci()->agent->platform(),
            'sender_agent' => ci()->agent->agent_string()
        );

        // -------------------------------------
        // Get the entry to pass to the template.
        // -------------------------------------

        $params = array(
            'id'     => $entry_id,
            'stream' => $stream->stream_slug
        );

        $rows = ci()->row_m->get_rows($params, ci()->streams_m->get_stream_fields($stream->id), $stream);

        $data['entry'] = $rows['rows'];

        // -------------------------------------
        // Parse the body and subject
        // -------------------------------------

        $layout->body = html_entity_decode(
            ci()->parser->parse_string(
                str_replace(array('&quot;', '&#39;'), array('"', "'"), $layout->body),
                $data,
                true
            )
        );

        $layout->subject = html_entity_decode(
            ci()->parser->parse_string(
                str_replace(array('&quot;', '&#39;'), array('"', "'"), $layout->subject),
                $data,
                true
            )
        );

        // -------------------------------------
        // Set From
        // -------------------------------------
        // We accept an email address from or
        // a name/email separated by a pipe (|).
        // -------------------------------------

        ci()->load->library('Email');

        if (isset($from) and $from) {
            $email_pieces = explode('|', $from);

            // For two segments we process it as email_address|name
            if (count($email_pieces) == 2) {
                $email_address = $this->processEmailAddress($email_pieces[0]);
                $name          = (ci()->input->post($email_pieces[1])) ?
                    ci()->input->post($email_pieces[1]) : $email_pieces[1];

                ci()->email->from($email_address, $name);
            } else {
                ci()->email->from($this->processEmailAddress($email_pieces[0]));
            }
        } else {
            // Hmm. No from address. We'll just use the site setting.
            ci()->email->from(Settings::get('server_email'), Settings::get('site_name'));
        }

        // -------------------------------------
        // Set Email Data
        // -------------------------------------

        ci()->email->to($emails);
        ci()->email->subject($layout->subject);
        ci()->email->message($layout->body);

        // -------------------------------------
        // Send, Log & Clear
        // -------------------------------------

        $return = ci()->email->send();

        ci()->email->clear();

        return $return;
    }

    /**
     * Build Fields
     *
     * Builds fields (no validation)
     *
     */
    // $stream_fields, $values = array(), $row = null, $this->method = 'new', $skips = array(), $required = '<span>*</span>'
    /**
     * Process an email address - if it is not
     * an email address, pull it from post data.
     *
     * @access    private
     *
     * @param    email
     *
     * @return    string
     */
    private function processEmailAddress($email)
    {
        if (strpos($email, '@') === false and ci()->input->post($email)) {
            return ci()->input->post($email);
        }

        return $email;
    }

    /**
     * Set Rules
     *
     * Set the rules from the stream fields
     *
     * @access    public
     *
     * @param    obj    - fields to set rules for
     * @param    string - method - edit or new
     * @param    array  - fields to skip
     * @param    bool   - return the array or set the validation
     * @param    mixed  - array or true
     */
    // $stream_fields, $method, $skips = array(), $return_array = false, $row_id = null

    public function buildFields()
    {
        foreach ($this->assignments as $k => &$field) {
            if ($type = $this->entry->getFieldType($field->field_slug) and !in_array(
                    $field->field_slug,
                    $this->getSkips(array())
                )
            ) {

                // Set defaults
                $type->setDefaults($this->defaults);

                // Set the error if there is one
                $field->error = ci()->form_validation->error($field->form_slug);

                // Determine the value
                if ($field->error) {
                    ci()->form_validation->set_value($field->form_slug);
                } else {
                    $field->value = $type->value = $this->entry->{$type->getColumnName()};
                }

                // Get some general info
                $field->form_slug  = $type->getFormSlug();
                $field->field_slug = $field->field_slug;
                $field->is_hidden  = (bool)in_array($field->field_slug, $this->get('hidden', array()));

                // Get the form input flavors
                $field->form_input = $type->getInput();
                $field->input_row  = $type->formInputRow();

                // Translate the instructions
                $field->instructions = lang_label($field->instructions);
                $field->placeholder  = lang_label($field->getParameter('placeholder'));

                // Set even/odd
                $field->odd_even = (($k + 1) % 2 == 0) ? 'even' : 'odd';
            } else {
                unset($this->assignments[$k]); // Get rid of it
            }
        }

        // $stream_fields, $skips, $values
        $this->runFieldTypeEvents();

        return $this->assignments;
    }

    public function runFieldTypeEvents()
    {
        if (!$this->assignments or (!is_array($this->assignments) and !is_object($this->assignments))) {
            return null;
        }

        foreach ($this->assignments as $field) {
            // We need the slug to go on.
            if (!$type = $field->getType($this->entry)) {
                continue;
            }

            if (!in_array($field->field_slug, $this->getSkips(array()))) {

                // If we haven't called it (for dupes),
                // then call it already.
                if (!in_array($field->field_type, $this->fieldTypeEventsRun)) {
                    $type->event();

                    $this->fieldTypeEventsRun[] = $field->field_type;
                }

                // Run field events per field regardless if the type
                // event has been ran yet
                $type->fieldEvent();
            }
        }
    }

    /**
     * Send Email
     *
     * Sends emails for a single notify group.
     *
     * @access    public
     *
     * @param    string $notify   a or b
     * @param    int    $entry_id the entry id
     * @param    string $this     ->method    edit or new
     * @param    obj    $stream   the stream
     *
     * @return    void
     */
    // $notify, $entry_id, $this->method, $stream


    public function runFieldTypePublicEvents()
    {
        if (!$this->assignments or (!is_array($this->assignments) and !is_object($this->assignments))) {
            return null;
        }

        foreach ($this->assignments as $field) {
            // We need the slug to go on.
            if (!$type = $field->getType($this->entry)) {
                continue;
            }

            if (!in_array($field->field_slug, $this->skips)) {
                // If we haven't called it (for dupes),
                // then call it already.
                if (!in_array($field->field_type, $this->fieldTypePublicEventsRun)) {
                    $type->publicEvent();

                    $this->fieldTypePublicEventsRun[] = $field->field_type;
                }

                // Run field events per field regardless if the type
                // event has been ran yet
                $type->publicFieldEvent();
            }
        }
    }

    /**
     * Get field types available
     *
     * @return array
     */
    public function getFieldTypes()
    {
        if (empty($this->fieldTypes)) {
            foreach ($this->assignments as $field) {
                if ($type = $this->entry->getFieldType($field->field_slug)) {
                    $this->fieldTypes[$field->field_slug] = $type;
                }
            }
        }

        return $this->fieldTypes;
    }

    public function render($return = false)
    {
    }
}
