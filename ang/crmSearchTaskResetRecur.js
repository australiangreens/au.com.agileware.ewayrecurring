const controller = function ($scope, CRM) {
  const ts = $scope.ts = CRM.ts('au.com.agileware.ewayrecurring');
  const ctrl = this;

  ctrl.next_date = '';

  ctrl.save = function () {
    var updates = {
      values: {
        'contribution_status_id:name': 'In Progress',
        'failure_count': 0
      },
      where: [['id', 'IN', ctrl.ids]]
    };

    if (ctrl.next_date) {
      updates.values.next_sched_contribution_date = ctrl.next_date;
    }

    CRM.api4('ContributionRecur', 'update', updates).then(function () {
      CRM.alert(
        ts('Successfully reset %1 records.', { 1: ctrl.idsCount }),
        ts('Update Complete'),
        'success'
      );
      ctrl.onFinish();
    }, function (err) {
      CRM.alert(err.message, ts('Error'), 'error');
    });
  };
};

(function(angular, $, _) {
  angular.module('ewayrecurring', CRM.angRequires('crmUi'))
  angular.module('ewayrecurring').component('crmSearchTaskResetRecur', {
    bindings: {
      ids: '<',
      idsCount: '<',
      onFinish: '&'
    },
    templateUrl: '~/ewayrecurring/crmSearchTaskResetRecur.html',
    controller
  });
})(angular, CRM.$, CRM._);