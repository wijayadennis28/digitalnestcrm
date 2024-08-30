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
        {{#if linkTranslated}} <span class="text-muted chevron-right"></span> {{{linkTranslated}}}{{/if}}
        {{#if parentEntityTypeTranslated}} <span class="text-muted chevron-right"></span> {{{parentEntityTypeTranslated}}}{{/if}}

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

        {{#if actionData.linkList}}
        {{#if actionData.linkList.length}}
        <div class="field-row cell form-group" data-field="linkList">
            <label class="control-label small">{{translate 'linkListShort' category='fields' scope='Workflow'}}</label>
            <div class="field small" data-name="linkList">{{{linkList}}}</div>
        </div>
        {{/if}}
        {{/if}}

        <div class="field hidden" data-name="formula">{{{formula}}}</div>

    </div>
</div>