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
  activity: Activity
  last_seen: string | null
  roles: string[]
}

export interface RoleOption {
  key: string
  name: string
  description: string | null
  scope_kind: 'none' | 'tribe' | 'gem' | 'department'
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
}

export interface ExerciseListItem {
  id: number
  title: string
  type: string
  type_label: string
  target: string
  due_date: string | null
  responses_count: number
  created_at: string
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
  due_date: string | null
  my_response: string | null
  completed: boolean
}

export type AnnouncementCategory = 'info' | 'important' | 'evenement'

export interface AnnouncementAdminItem {
  id: number
  title: string | null
  category: AnnouncementCategory
  image_url: string | null
  target: string
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
  target_type: 'all' | 'tribe' | 'department'
  target_id: number | null
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
    last_seen: string | null
    last_login_at: string | null
  }
  profile: Profile | null
  spiritual_profile: SpiritualProfileData | null
  roles: RoleAssignment[]
}

export interface Stats {
  total: number
  active: number
  inactive: number
  completed: number
  by_tribe: { name: string; total: number }[]
  recent: { user_id: number; full_name: string; photo_url: string | null }[]
}
