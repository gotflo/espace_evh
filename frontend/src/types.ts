export interface Tribe { id: number; name: string }
export interface Department { id: number; name: string }
export interface Gem { id: number; name: string; tribe_id?: number }

export interface GemAdminItem {
  id: number
  name: string
  tribe_id: number
  tribe: string | null
  leader_user_id: number | null
  leader: string | null
  members_count: number
}

export interface Profile {
  id: number
  matricule: string | null
  first_name: string | null
  last_name: string | null
  birth_date: string | null
  birth_day: number | null
  birth_month: number | null
  gender: string | null
  email: string | null
  facebook: string | null
  marital_status: string | null
  spouse_name: string | null
  wedding_day: number | null
  wedding_month: number | null
  has_children: boolean | null
  completion: number
  civility: string | null
  children_count: number | null
  tshirt_size: string | null
  year_verse: string | null
  photo_url: string | null
  photo_path: string | null
  tribe_id: number | null
  gem_id: number | null
  is_completed: boolean
  full_name: string
  tribe?: Tribe | null
  gem?: Gem | null
  departments?: Department[]
}

export interface SpiritualProfileData {
  conversion_year: number | null
  conversion_verse: string | null
  baptism_immersion_date: string | null
  baptism_holy_spirit: string | null
  speaks_tongues: boolean | null
  tongues_since_year: number | null
  active_member: boolean | null
  prayer_frequency: string | null
  gifts_known: boolean | null
  gifts_detail: string | null
  last_prayer_subject: string | null
  joyful_service: string | null
  focus_effort: string | null
}

export interface User {
  id: number
  phone: string
}

export type Activity = 'active' | 'inactive'

export interface UserRole {
  key: string
  name: string
  scope_kind: string | null
  scope_id: number | null
  scope_name?: string | null
}

export interface AuthPayload {
  user: User
  profile: Profile | null
  profile_completed: boolean
  completion: ProfileCompletion | null
  is_super_admin: boolean
  roles: UserRole[]
  permissions: string[]
}

export interface MemberListItem {
  user_id: number
  full_name: string
  phone: string | null
  photo_url: string | null
  tribe: string | null
  departments: string[]
  is_completed: boolean
  completion: number
  activity: Activity
  last_seen: string | null
  roles: string[]
  can_manage: boolean
  fiss_current: boolean
}

export interface RoleOption {
  key: string
  name: string
  description: string | null
  scope_kind: 'none' | 'tribe' | 'gem' | 'department' | 'member'
}

export interface ManagedRole extends RoleOption {
  id: number
  is_system: boolean
  permission_keys: string[]
}

export interface PermissionGroup {
  group: string
  permissions: { key: string; name: string }[]
}

export interface OrgItem {
  id: number
  name: string
  members_count: number
  tracks_rehearsal?: boolean
  description?: string | null
  leaders?: { user_id: number; name: string }[]
}

export interface JournalEntry {
  id: number
  type: string
  type_label: string
  entry_date: string
  note: string | null
  author: string | null
}

export interface MilestoneItem {
  key: string
  label: string
  reached: boolean
  reached_at: string | null
}

export interface SpiritualData {
  entries: JournalEntry[]
  milestones: MilestoneItem[]
  entry_types: { key: string; label: string }[]
}

export type AttendanceStatus = 'present' | 'retard' | 'absent_justifie' | 'absent'
export interface AttendanceMember {
  user_id: number
  full_name: string
  photo_url: string | null
  tribe: string | null
  present: boolean
  status: AttendanceStatus | null
}

export interface RosterData {
  date: string
  event: string
  members: AttendanceMember[]
  /** Fideles inactifs masques de la feuille (retrouvables par recherche) */
  inactive_hidden: number
}

export interface ExerciseVideo { id: string; duration: number | null; thumbnail: string; url: string }

export interface ExerciseListItem {
  id: number
  title: string
  content: string
  type: string
  type_label: string
  target: string
  scopes: AudienceScope[]
  video: ExerciseVideo | null
  requires_response: boolean
  due_date: string | null
  closes_at: string | null
  is_closed: boolean
  responses_count: number
  views_count: number
  views_completed_count: number
  created_at: string
}

export type ExerciseStatus = 'todo' | 'in_progress' | 'done'

export interface ExerciseTrackingRow {
  user_id: number
  name: string
  tribe: string | null
  active: boolean
  status: ExerciseStatus
  percent: number
  watched_seconds: number
  seek_count: number
  skipped_seconds: number
  max_rate: number | null
  video_completed_at: string | null
  last_activity: string | null
  response: string | null
  responded_at: string | null
}

export interface ExerciseTracking {
  exercise: {
    id: number; title: string; content: string; type_label: string; video: ExerciseVideo | null
    requires_response: boolean; closes_at: string | null; is_closed: boolean; target: string
  }
  summary: { total: number; done: number; in_progress: number; todo: number; skipped: number }
  members: ExerciseTrackingRow[]
}

export interface ExerciseResponseItem {
  user_id: number
  name: string
  response: string
  completed_at: string | null
}

export interface MyExercise {
  id: number
  title: string
  content: string
  type: string
  video: ExerciseVideo | null
  requires_response: boolean
  due_date: string | null
  closes_at: string | null
  is_closed: boolean
  status: ExerciseStatus
  percent: number
  video_completed: boolean
  seek_count: number
  my_response: string | null
  completed: boolean
  created_at: string | null
  /** Detail seulement : position de reprise (secondes) */
  resume_at?: number
}

export type AnnouncementCategory = 'info' | 'important' | 'evenement'

export interface AnnouncementAdminItem {
  id: number
  title: string | null
  category: AnnouncementCategory
  image_url: string | null
  target: string
  scopes: AudienceScope[]
  recipients_count: number
  created_at: string
  author: string | null
}

export interface MyAnnouncement {
  id: number
  title: string | null
  body: string | null
  image_url: string | null
  category: AnnouncementCategory
  created_at: string
  read: boolean
}

export type EventCategory = 'culte' | 'priere' | 'formation' | 'reunion' | 'sortie' | 'autre'

export interface EventAdminItem {
  id: number
  title: string
  description: string | null
  image_url: string | null
  category: EventCategory
  starts_at: string
  ends_at: string | null
  location: string | null
  all_day: boolean
  recurrence: Recurrence
  recurrence_label: string | null
  recurrence_until: string | null
  remind_all?: boolean
  next_occurrence: string | null
  scopes: AudienceScope[]
  target: string
  is_past: boolean
  author: string | null
  going_count: number
  volunteer_count: number
}

export interface MyEvent {
  id: number
  title: string
  description: string | null
  image_url: string | null
  category: EventCategory
  starts_at: string
  ends_at: string | null
  location: string | null
  going_count: number
  my_response: 'present' | 'absent' | null
  my_volunteer: boolean
}

export interface EventParticipant {
  user_id: number
  name: string
  volunteer: boolean
}
export interface EventParticipants {
  title: string
  going: EventParticipant[]
  volunteers: EventParticipant[]
  absent_count: number
}

export type RequestCategory = 'rendez-vous' | 'aide' | 'priere' | 'question' | 'autre'
export type RequestStatus = 'nouvelle' | 'en_cours' | 'traitee'

export interface MyRequest {
  id: number
  category: RequestCategory
  category_label: string
  subject: string | null
  message: string
  status: RequestStatus
  status_label: string
  reply: string | null
  replied_at: string | null
  created_at: string
}
export interface AdminRequest extends MyRequest {
  replied_by: string | null
  sender: string
  sender_phone: string | null
  handler: string | null
}

export interface MySpiritualEntry {
  id: number
  type: string
  type_label: string
  entry_date: string
  note: string | null
  author: string | null
  mine: boolean
}
export interface MySpiritualData {
  entries: MySpiritualEntry[]
  milestones: MilestoneItem[]
  entry_types: { key: string; label: string }[]
}

export interface MyOverviewData {
  assiduite: number
  attendance: { present: number; sessions: number; rate: number | null; recent: { date: string; event: string; kind: string; status: string }[] }
  fiss: { filled: boolean; period_label: string; score: number | null; trend: { period: string; label: string; score: number | null }[] }
  note_moyenne: number | null
  parcours: number
  rehearsal: {
    total: number
    present: number
    retard: number
    absent_justifie: number
    absent: number
    punctuality_rate: number | null
  } | null
}

export interface FissIndexLevel { range: string; label: string }
export interface FissIndex { title: string; scale: string; levels: FissIndexLevel[] }
export interface FissForm {
  id?: number
  period?: string
  period_label?: string
  meditation: number | null
  priere: number | null
  jeune: number | null
  sanctification_corps: string | null
  sanctification_ame: string | null
  sanctification_esprit: string | null
  situation_financiere: number | null
  situation_familiale: number | null
  situation_conjugale: number | null
  comment: string | null
  vie_spirituelle_total?: number
  vie_sociale_total?: number
  spiritual_score?: number | null
  social_score?: number | null
  submitted_at?: string | null
  locked?: boolean
  editable?: boolean
  can_edit_until?: string | null
  edit_count?: number
  requests_used?: number
  requests_left?: number
  pending_request?: { id: number; reason: string; created_at: string } | null
  last_decision?: { status: string; comment: string | null; decided_at: string | null } | null
}
export type FissReminderLevel = 'none' | 'info' | 'advance' | 'urgent'
export interface FissData {
  period: string
  period_label: string
  filled: boolean
  reminder: { level: FissReminderLevel; days_left: number | null }
  current: FissForm | null
  history: FissForm[]
  indices: Record<string, FissIndex>
  max_requests: number
}

export interface EvaluationItem {
  id: number
  type: string
  type_label: string
  title: string | null
  score: number
  max_score: number
  stars: number
  evaluated_on: string
  comment: string | null
  author?: string | null
}
export interface EvalTypeAverage { type: string; type_label: string; average: number; count: number }
export interface MyEvaluations {
  evaluations: EvaluationItem[]
  average: number | null
  by_type: EvalTypeAverage[]
}

export interface RoleAssignment {
  assignment_id: number
  key: string
  name: string
  scope_kind: string | null
  scope_id: number | null
  scope_name: string | null
}

export interface MemberDetailData {
  user: {
    id: number
    phone: string
    activity: Activity
    activity_override: string | null
    activity_changed_at: string | null
    last_seen: string | null
    last_login_at: string | null
  }
  profile: Profile | null
  spiritual_profile: SpiritualProfileData | null
  completion: ProfileCompletion | null
  family: FamilyOverview
  can_manage: boolean
  led_departments: { id: number; name: string }[]
  roles: RoleAssignment[]
}

export interface Stats {
  total: number
  active: number
  inactive: number
  completed: number
  incomplete_profiles: number
  fiss_filled: number
  fiss_rate: number | null
  by_tribe: { name: string; total: number }[]
  recent: NewMember[]
  new_members: NewMemberCounts
}

// ---------------------------------------------------------------- Nouveaux inscrits
export interface NewMember {
  user_id: number
  full_name: string
  photo_url: string | null
  phone: string | null
  tribe: string | null
  is_completed: boolean
  registered_at: string | null
  days_ago: number | null
  welcomed: boolean
  welcomed_at: string | null
  welcomed_by: string | null
}
export interface NewMemberCounts { to_welcome: number; recent: number }

// ---------------------------------------------------------------- Evenements / calendrier
export type Recurrence = 'none' | 'daily' | 'weekly' | 'biweekly' | 'monthly'

/** Une occurrence d'evenement (les evenements recurrents en ont plusieurs). */
export interface EventOccurrence {
  key: string
  kind: 'event'
  event_id: number
  occurs_on: string
  title: string
  description: string | null
  image_url: string | null
  category: EventCategory
  location: string | null
  starts_at: string
  ends_at: string | null
  all_day: boolean
  recurring: boolean
  recurrence: Recurrence
  recurrence_label: string | null
  target: string
  scopes: AudienceScope[]
  recurrence_until: string | null
  series_starts_at: string
  series_ends_at: string | null
  /** Rendez-vous regulier (culte) : rappel a toute l'audience */
  remind_all?: boolean
  /** Agenda personnel (visible par soi seul) */
  personal: boolean
  /** Peut modifier / supprimer (auteur, ou responsable de la portee) */
  can_edit: boolean
  going_count: number
  my_response: 'present' | 'absent' | null
  my_volunteer: boolean
}
export interface CalendarBirthday {
  key: string
  kind: 'birthday'
  title: string
  date: string
  all_day: true
  people: { user_id: number; name: string }[]
}
export interface CalendarHoliday {
  key: string
  kind: 'holiday'
  holiday_kind: 'ferie' | 'fete' | 'chretien'
  title: string
  date: string
  all_day: true
}
export interface CalendarTask {
  key: string
  kind: 'task'
  title: string
  date: string
  all_day: true
  done: boolean
  url: string
}
export interface CalendarWedding {
  key: string
  kind: 'wedding'
  title: string
  date: string
  all_day: true
  people: { user_id: number; name: string }[]
}
export type CalendarItem = EventOccurrence | CalendarBirthday | CalendarHoliday | CalendarTask | CalendarWedding
export interface CalendarData {
  from: string
  to: string
  events: EventOccurrence[]
  birthdays: CalendarBirthday[]
  weddings: CalendarWedding[]
  holidays: CalendarHoliday[]
  tasks: CalendarTask[]
}

// ---------------------------------------------------------------- Notifications
export interface AppNotification {
  id: number
  type: string
  type_label: string
  title: string
  body: string | null
  url: string | null
  read: boolean
  created_at: string | null
}
export interface NotificationPage {
  notifications: AppNotification[]
  has_more: boolean
  unread: number
}

// ---------------------------------------------------------------- Services (departements)
export interface ServiceItem {
  id: number
  name: string
  description: string | null
  members_count: number
  leader: string | null
  leader_photo_url: string | null
  joined: boolean
  joined_at: string | null
  next_event: { title: string; starts_at: string } | null
}

// ---------------------------------------------------------------- Portee de publication
export type AudienceType = 'church' | 'tribe' | 'gem' | 'department'
export interface AudienceScope { type: AudienceType; id: number | null }
export interface AudienceOptions {
  church: boolean
  tribes: { id: number; name: string }[]
  gems: { id: number; name: string; tribe_id: number }[]
  departments: { id: number; name: string }[]
}

// ---------------------------------------------------------------- Profil : completion et famille
export interface ProfileCompletion {
  percent: number
  missing: { key: string; label: string }[]
  recommended: { key: string; label: string }[]
}
export interface FamilyPerson { user_id: number; full_name: string; tribe: string | null; photo_url: string | null; birth_month: number | null; has_spouse: boolean }
export interface FamilyOverview {
  spouse: { id: number; user_id: number | null; name: string | null; tribe: string | null; photo_url: string | null; status: 'pending' | 'confirmed' | 'declined' } | null
  spouse_name: string | null
  children: { id: number; name: string; birth_year: number | null; user_id: number | null; status: string }[]
  incoming: { id: number; relation: 'spouse' | 'child'; from: string; from_user_id: number }[]
  suggestions: FamilyPerson[]
}

// ---------------------------------------------------------------- Demandes (changement de tribu, FISS)
export interface TribeApproval { side: 'from' | 'to' | 'both'; decision: 'approved' | 'rejected'; comment: string | null; by: string | null; at: string | null }
export interface MyTribeRequest {
  id: number
  from: string | null
  to: string
  reason: string | null
  status: 'pending' | 'approved' | 'rejected' | 'cancelled'
  required_sides: string[]
  approved_sides: string[]
  approvals: TribeApproval[]
  created_at: string
  completed_at: string | null
}
export interface ValidationFissItem {
  id: number
  member: { user_id: number; name: string; tribe: string | null }
  period: string | null
  period_label: string | null
  reason: string
  request_number: number
  created_at: string
}
export interface ValidationTribeItem {
  id: number
  member: { user_id: number; name: string }
  from: string | null
  to: string
  reason: string | null
  my_sides: string[]
  required_sides: string[]
  approvals: TribeApproval[]
  created_at: string
}
export interface ValidationsData { fiss: ValidationFissItem[]; tribes: ValidationTribeItem[]; count: number }

// ---------------------------------------------------------------- Rapports
export interface ReportOptions { church: boolean; mine: boolean; tribes: { id: number; name: string }[] }
export interface ReportMonth {
  month: string
  label: string
  members: number
  new_members: number
  fiss_filled: number
  fiss_rate: number | null
  spiritual_score: number | null
  social_score: number | null
  vertumetre: number | null
  attendance_sessions: number
  attendance_present: number
  attendance_rate: number | null
  events: number
  participations: number
}
export interface ReportTribe {
  id: number
  name: string
  members: number
  active: number
  inactive: number
  fiss_rate: number | null
  spiritual_score: number | null
  spiritual_score_previous: number | null
  fiss_count: number
  completion_avg: number | null
}
export interface ReportPerson { user_id: number; name: string; tribe: string | null; since?: string | null; date?: string }
export interface ReportData {
  scope: { key: string; label: string }
  generated_at: string
  period: { from: string; to: string; months: number }
  kpis: {
    members: number
    active: number
    inactive: number
    new_members: number
    profile_completion_avg: number | null
    incomplete_profiles: number
    fiss_rate: number | null
    fiss_missing: number
    spiritual_score: number | null
    social_score: number | null
    vertumetre: number | null
    attendance_rate: number | null
    events: number
    participations: number
  }
  monthly: ReportMonth[]
  missing_fiss: ReportPerson[]
  inactive_members: ReportPerson[]
  new_members_list: ReportPerson[]
  tribes: ReportTribe[]
}
export interface ReportMemberRow {
  user_id: number
  name: string
  phone: string | null
  tribe: string | null
  gem: string | null
  status: Activity
  last_seen: string | null
  completion: number
  last_fiss: string | null
  fiss_current: boolean
  fiss_score: number | null
  attendance_3m: number
  vertumetre: number | null
}

// ---------------------------------------------------------------- Journal d'audit
export interface AuditEntry {
  id: number
  action: string
  label: string
  actor: string
  member?: string | null
  member_user_id?: number | null
  subject?: string | null
  period?: string | null
  old: Record<string, unknown> | null
  new: Record<string, unknown> | null
  context: Record<string, unknown> | null
  ip?: string | null
  created_at: string | null
}
