<?php

namespace App\Domains\PeopleConnector\Connector\Enums;

enum PeopleCapability: string
{
    case CompanyDirectory = 'company_directory';
    case EmployeeDirectory = 'employee_directory';
    case OrganizationDirectory = 'organization_directory';
    case ManagerHierarchy = 'manager_hierarchy';
    case UserDirectory = 'user_directory';
    case Payroll = 'payroll';
    case Attendance = 'attendance';
    case Leave = 'leave';
    case Claims = 'claims';
    case Training = 'training';
    case Documents = 'documents';
    case SingleSignOn = 'single_sign_on';
}
