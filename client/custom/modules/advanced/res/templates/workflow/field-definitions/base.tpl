{{#if readOnly}}
    <div class="subject">
        {{{subject}}}
    </div>
{{else}}
<div class="row">
    <div class="col-sm-2 subject-type">
        <span data-field="subjectType">{{{subjectTypeField}}}</span>
    </div>

    <div class="col-sm-6 subject">
        {{{subject}}}
    </div>
</div>
{{/if}}
