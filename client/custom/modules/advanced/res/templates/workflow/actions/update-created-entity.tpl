<div class="row">
    {{#unless readOnly}}
        <div class="col-md-1">
            <button class="btn btn-default btn-sm btn-icon" type="button" data-action='editAction'><span class="fas fa-pencil-alt fa-sm"></span></button>
            <div>
                <a class="btn btn-text btn-sm btn-icon drag-handle"><span class="fas fa-magnet fa-sm"></span></a>
            </div>
        </div>
    {{/unless}}

    <div class="col-md-10">
        {{translate actionType scope='Workflow' category='actionTypes'}}
        {{#if entityTypeTranslated}}{{#if linkTranslated}} <span class="text-muted chevron-right"></span> {{linkTranslated}}{{/if}} <span class="text-muted chevron-right"></span> {{{entityTypeTranslated}}}{{/if}}
        {{#if text}}
            '{{text}}'
        {{else}}
            {{#if numberId}} #{{numberId}}{{/if}}
        {{/if}}

        <div class="field-list small" style="margin-top: 12px;">
            {{#if actionData.fieldList}}
                {{#each actionData.fieldList}}
                    <div class="field-row cell form-group" data-field="{{./this}}">
                        <label class="control-label">{{translate ./this category='fields' scope=../linkedEntityName}}</label>
                        <div class="field-container field" data-field="{{./this}}"></div>
                    </div>
                {{/each}}
            {{/if}}
        </div>

        <div class="field hidden" data-name="formula">{{{formula}}}</div>
    </div>
</div>
