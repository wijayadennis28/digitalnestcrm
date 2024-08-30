<div class="row">
    <div class="cell cell-entityType col-sm-6 form-group">
        <label class="field-label-entityType control-label">{{translate 'entityType' scope='Report' category='fields'}}</label>
        <div class="field field-entityType" data-name="entityType">
           {{{entityType}}}
        </div>
    </div>
</div>
<div class="list-group no-side-margin">
    <div class="list-group-item">
        <h4 class="list-group-item-heading">{{translate 'Grid Report' scope='Report'}}</h4>
        <p>{{translate 'gridReportDescription' category='messages' scope='Report'}}</p>
        <div class="form-group">
            <button class="btn btn-primary" data-action="create" data-type="Grid">{{translate 'Create'}}</button>
        </div>
    </div>
    <div class="list-group-item">
        <h4 class="list-group-item-heading">{{translate 'List Report' scope='Report'}}</h4>
        <p>{{translate 'listReportDescription' category='messages' scope='Report'}}</p>
        <button class="btn btn-primary" data-action="create" data-type="List">{{translate 'Create'}}</button>
    </div>
</div>