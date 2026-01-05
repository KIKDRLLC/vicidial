export type LogicOp = 'AND' | 'OR';

export type CompareOp =
  | '='
  | '!='
  | '<'
  | '<='
  | '>'
  | '>='
  | 'IN'
  | 'NOT_IN'
  | 'LIKE'
  | 'STARTS_WITH'
  | 'ENDS_WITH'
  | 'CONTAINS'
  | 'IS_NULL'
  | 'IS_NOT_NULL'
  | 'OLDER_THAN_DAYS'
  | 'NEWER_THAN_DAYS';

export type FieldName =
  | 'lead_id'
  | 'list_id'
  | 'status'
  | 'called_count'
  | 'called_since_last_reset'
  | 'entry_date'
  | 'last_local_call_time'
  | 'owner'
  | 'vendor_lead_code'
  | 'source_id'
  | 'phone_number'
  | 'state'
  | 'postal_code'
  | 'country_code'
  | 'gmt_offset_now';

export type ConditionRule = {
  field: FieldName;
  op: CompareOp;
  value?: any;
};

export type ConditionGroup = {
  op: LogicOp;
  rules: Array<ConditionRule | ConditionGroup>;
};

export type ConditionSpec = {
  where: ConditionGroup;
  sampleLimit?: number;
};
