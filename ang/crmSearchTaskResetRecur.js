(function(angular, $, _) {
  angular.module('ewayrecurring').component('crmSearchTaskResetRecur', {
    bindings: {
      ids: '<',
      idsCount: '<',
      onFinish: '&'
    },
    templateUrl: '~/myextension/crmSearchTaskResetRecur.html',
    controller: function($scope, CRM) {
      var ts = $scope.ts = CRM.ts('au.com.agileware.ewayrecurring');
      var ctrl = this;

      ctrl.next_date = '';

      ctrl.save = function() {
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

        CRM.api4('ContributionRecur', 'update', updates).then(function() {
          CRM.alert(
            ts('Successfully reset %1 records.', {1: ctrl.idsCount}), 
            ts('Update Complete'), 
            'success'
          );
          ctrl.onFinish();
        }, function(err) {
          CRM.alert(err.message, ts('Error'), 'error');
        });
      };
    }
  });
})(angular, CRM.$, CRM._);