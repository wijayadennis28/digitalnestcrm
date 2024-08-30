{{#if readOnly}}
    <div class="subject">
        {{{subject}}}
    </div>
{{else}}
<div class="row">
    <div class="col-sm-3 subject-type">
        <span data-field="subjectType">{{{subjectTypeField}}}</span>
    </div>

    <div class="col-sm-9 subject">
        {{{subject}}}
    </div>
</div>
{{/if}}
