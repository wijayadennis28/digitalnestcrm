<div class="panel panel-default no-side-margin">
    <div class="panel-body panel-body-form">
        <div class="row">
            <div class="cell col-sm-6 form-group">
                <label class="control-label">{{translate 'Link' scope='Workflow'}}</label>
                <div class="field" data-name="link">{{{link}}}</div>
            </div>
        </div>
        <div class="row">
            <div class="cell col-sm-6 form-group hidden" data-name="parentEntityType">
                <label class="control-label">{{translate 'Entity Type' scope='Workflow'}}</label>
                <div class="field" data-name="parentEntityType">
                    {{{parentEntityType}}}
                </div>
            </div>
        </div>

        <div class="row">
            <div class="cell col-sm-6 form-group add-field-container">
                {{{addField}}}
            </div>
        </div>

        <div class="row">
            <div class="cell col-md-12">
                <div class="field-definitions form-group">
                </div>
            </div>
        </div>

        <div class="row">
            <div class="cell form-group col-md-12 hidden" data-name="formula">
                <label class="control-label">{{translate 'Formula' scope='Workflow'}}</label>
                <div class="field" data-name="formula"></div>
            </div>
        </div>
    </div>
</div>
