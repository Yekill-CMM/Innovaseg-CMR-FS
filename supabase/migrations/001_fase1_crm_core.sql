-- =====================================================================
-- CRM COMERCIAL · Migración 001 · Fase 1 (CRM Core)
-- Motor: PostgreSQL 15+ sobre Supabase (usa auth.users y auth.uid())
-- Contenido: catálogos, usuarios/roles, empresas, sitios, contactos,
-- leads + scoring, oportunidades, ofertas básicas con versiones,
-- actividades, tareas, notas, alertas, auditoría, RLS y vistas de KPI.
-- =====================================================================

create extension if not exists pgcrypto;
create extension if not exists pg_trgm;
create extension if not exists citext;

create schema if not exists crm;
set search_path = crm, public;

-- ---------------------------------------------------------------------
-- 1. TIPOS
-- ---------------------------------------------------------------------
create type crm.app_role        as enum ('admin','gerente','bd','kam','ingenieria','operaciones','backoffice','consulta');
create type crm.visibility      as enum ('own','team','all');
create type crm.currency_code   as enum ('CLP','UF','USD');
create type crm.opp_status      as enum ('abierta','ganada','perdida','postergada');
create type crm.task_status     as enum ('Pendiente','En curso','Completada','Cancelada');
create type crm.task_priority   as enum ('Alta','Media','Baja');
create type crm.alert_severity  as enum ('info','amarilla','roja');
create type crm.alert_status    as enum ('activa','resuelta','pospuesta');
create type crm.entity_type     as enum ('lead','company','contact','opportunity','offer','contract','project');
create type crm.offer_status    as enum ('Borrador','En revisión','Aprobación interna','Enviada','En seguimiento',
                                         'Revisada por cliente','Negociación','Aceptada','Rechazada','Vencida','Cancelada');

-- ---------------------------------------------------------------------
-- 2. FUNCIONES UTILITARIAS
-- ---------------------------------------------------------------------
-- Valida RUT chileno (módulo 11). Acepta con o sin puntos/guión.
create or replace function crm.rut_is_valid(p_rut text) returns boolean
language plpgsql immutable as $$
declare
  v text := upper(regexp_replace(coalesce(p_rut,''), '[^0-9kK]', '', 'g'));
  body text; dv text; s int := 0; m int := 2; r int; calc text;
begin
  if length(v) < 8 or length(v) > 9 then return false; end if;
  body := left(v, length(v)-1); dv := right(v,1);
  if body !~ '^[0-9]+$' then return false; end if;
  for i in reverse length(body)..1 loop
    s := s + substr(body,i,1)::int * m;
    m := case when m = 7 then 2 else m + 1 end;
  end loop;
  r := 11 - (s % 11);
  calc := case r when 11 then '0' when 10 then 'K' else r::text end;
  return calc = dv;
end $$;

-- Normaliza RUT a "12345678-9" para unicidad.
create or replace function crm.rut_normalize(p_rut text) returns text
language sql immutable as $$
  select left(v, length(v)-1) || '-' || right(v,1)
  from (select upper(regexp_replace(coalesce(p_rut,''), '[^0-9kK]', '', 'g')) as v) x
$$;

create or replace function crm.touch_updated_at() returns trigger
language plpgsql as $$ begin new.updated_at := now(); return new; end $$;

-- ---------------------------------------------------------------------
-- 3. USUARIOS, EQUIPOS, ROLES Y PERMISOS
-- ---------------------------------------------------------------------
create table crm.teams (
  id          uuid primary key default gen_random_uuid(),
  name        text not null unique,
  manager_id  uuid,
  created_at  timestamptz not null default now()
);

create table crm.users (
  id           uuid primary key references auth.users(id) on delete restrict,
  email        citext not null unique,
  full_name    text not null,
  phone        text,
  role         crm.app_role not null default 'consulta',
  team_id      uuid references crm.teams(id),
  manager_id   uuid references crm.users(id),
  is_active    boolean not null default true,
  last_login   timestamptz,
  created_at   timestamptz not null default now(),
  updated_at   timestamptz not null default now()
);
alter table crm.teams add constraint teams_manager_fk foreign key (manager_id) references crm.users(id);

create table crm.role_permissions (
  role         crm.app_role not null,
  module       text not null,
  can_read     boolean not null default false,
  can_create   boolean not null default false,
  can_update   boolean not null default false,
  can_delete   boolean not null default false,
  can_export   boolean not null default false,
  see_costs    boolean not null default false,
  visibility   crm.visibility not null default 'own',
  primary key (role, module)
);

-- Helpers de seguridad (SECURITY DEFINER para usarlos en RLS sin recursión)
create or replace function crm.current_app_role() returns crm.app_role
language sql stable security definer set search_path = crm as $$
  select role from crm.users where id = auth.uid() and is_active
$$;

create or replace function crm.is_manager() returns boolean
language sql stable security definer set search_path = crm as $$
  select coalesce(crm.current_app_role() in ('admin','gerente'), false)
$$;

create or replace function crm.can_read_all() returns boolean
language sql stable security definer set search_path = crm as $$
  select coalesce(crm.current_app_role() in ('admin','gerente','backoffice','consulta'), false)
$$;

create or replace function crm.is_active_user() returns boolean
language sql stable security definer set search_path = crm as $$
  select exists (select 1 from crm.users where id = auth.uid() and is_active)
$$;

-- ---------------------------------------------------------------------
-- 4. CATÁLOGOS CONFIGURABLES
-- ---------------------------------------------------------------------
create table crm.industries (
  id            uuid primary key default gen_random_uuid(),
  name          text not null,
  parent_id     uuid references crm.industries(id),
  is_strategic  boolean not null default false,
  score_points  int not null default 4 check (score_points between 0 and 12),
  is_active     boolean not null default true,
  sort_order    int not null default 100,
  unique (name, parent_id)
);

create table crm.service_categories (
  id          uuid primary key default gen_random_uuid(),
  name        text not null unique,
  sort_order  int not null default 100
);

create table crm.services (
  id            uuid primary key default gen_random_uuid(),
  category_id   uuid not null references crm.service_categories(id),
  name          text not null unique,
  billing_type  text not null check (billing_type in ('recurrente','unico','mixto')),
  is_active     boolean not null default true
);

create table crm.pipeline_stages (
  id                   uuid primary key default gen_random_uuid(),
  pipeline             text not null check (pipeline in ('lead','opportunity')),
  code                 text not null,
  name                 text not null,
  sort_order           int not null,
  default_probability  int check (default_probability between 0 and 100),
  exit_criteria        jsonb not null default '[]',
  is_closed            boolean not null default false,
  is_won               boolean not null default false,
  unique (pipeline, code),
  unique (pipeline, sort_order)
);

create table crm.loss_reasons (
  id         uuid primary key default gen_random_uuid(),
  name       text not null unique,
  is_active  boolean not null default true
);

create table crm.competitors (
  id          uuid primary key default gen_random_uuid(),
  name        text not null unique,
  rut         text,
  strengths   text,
  weaknesses  text,
  notes       text,
  created_at  timestamptz not null default now()
);

-- Reglas de scoring: una fila por opción de cada variable.
create table crm.scoring_options (
  id          uuid primary key default gen_random_uuid(),
  variable    text not null,          -- sitios, potencial, necesidad, ...
  label       text not null,
  points      int  not null check (points >= 0),
  sort_order  int  not null,
  is_active   boolean not null default true,
  unique (variable, sort_order)
);

-- Reglas del motor de follow-up y alertas (todas configurables)
create table crm.followup_rules (
  id           uuid primary key default gen_random_uuid(),
  code         text not null unique,   -- offer_remind, offer_yellow, ...
  entity_type  crm.entity_type not null,
  description  text not null,
  days         int not null check (days > 0),
  severity     crm.alert_severity not null,
  is_active    boolean not null default true,
  updated_at   timestamptz not null default now()
);

create table crm.sales_targets (
  id           uuid primary key default gen_random_uuid(),
  user_id      uuid not null references crm.users(id),
  period       date not null check (extract(day from period) = 1),  -- primer día del mes
  target_type  text not null default 'TCV' check (target_type in ('TCV','MRR','UNICO')),
  amount_clp   numeric(16,0) not null check (amount_clp >= 0),
  unique (user_id, period, target_type)
);

create table crm.exchange_rates (
  rate_date  date not null,
  currency   crm.currency_code not null check (currency <> 'CLP'),
  value_clp  numeric(14,4) not null check (value_clp > 0),
  source     text not null default 'mindicador',
  primary key (rate_date, currency)
);

create or replace function crm.rate_clp(p_currency crm.currency_code, p_date date default current_date)
returns numeric language sql stable as $$
  select case when p_currency = 'CLP' then 1
    else (select value_clp from crm.exchange_rates
          where currency = p_currency and rate_date <= p_date
          order by rate_date desc limit 1) end
$$;

-- ---------------------------------------------------------------------
-- 5. EMPRESAS, SITIOS Y CONTACTOS
-- ---------------------------------------------------------------------
create table crm.companies (
  id                    uuid primary key default gen_random_uuid(),
  legal_name            text not null,
  trade_name            text,
  rut                   text not null,
  rut_norm              text generated always as (crm.rut_normalize(rut)) stored,
  industry_id           uuid references crm.industries(id),
  subindustry_id        uuid references crm.industries(id),
  segment               text,
  size                  text check (size in ('Pequeña','Mediana','Grande')),
  est_revenue_clp       numeric(18,0),
  employees             int check (employees >= 0),
  parent_company_id     uuid references crm.companies(id),
  region                text,
  city                  text,
  address               text,
  website               text,
  linkedin              text,
  relationship_status   text not null default 'Prospecto'
                        check (relationship_status in ('Prospecto','Cliente','Ex cliente','Partner','EPC / Ingeniería')),
  owner_id              uuid not null references crm.users(id),
  manager_id            uuid references crm.users(id),
  commercial_potential  text check (commercial_potential in ('Alto','Medio','Bajo')),
  strategic_level       char(1) check (strategic_level in ('A','B','C')),
  source                text,
  last_activity_at      date,
  next_activity_at      date,
  notes                 text,
  created_by            uuid references crm.users(id) default auth.uid(),
  created_at            timestamptz not null default now(),
  updated_at            timestamptz not null default now(),
  deleted_at            timestamptz,
  constraint companies_rut_valid check (crm.rut_is_valid(rut))
);
create unique index companies_rut_uq     on crm.companies(rut_norm) where deleted_at is null;
create index companies_owner_idx         on crm.companies(owner_id);
create index companies_industry_idx      on crm.companies(industry_id);
create index companies_last_act_idx      on crm.companies(last_activity_at);
create index companies_name_trgm         on crm.companies using gin ((legal_name || ' ' || coalesce(trade_name,'')) gin_trgm_ops);

create table crm.sites (
  id           uuid primary key default gen_random_uuid(),
  company_id   uuid not null references crm.companies(id) on delete cascade,
  name         text not null,
  site_type    text not null check (site_type in ('Casa matriz','Planta','Centro de distribución','Faena',
                 'Parque fotovoltaico','Parque eólico','Subestación','Oficina','Puerto','Otro')),
  region       text,
  city         text,
  address      text,
  lat          numeric(9,6),
  lng          numeric(9,6),
  criticality  text check (criticality in ('Alta','Media','Baja')),
  notes        text,
  created_at   timestamptz not null default now(),
  unique (company_id, name)
);
create index sites_company_idx on crm.sites(company_id);

create table crm.contacts (
  id                uuid primary key default gen_random_uuid(),
  company_id        uuid not null references crm.companies(id) on delete cascade,
  site_id           uuid references crm.sites(id) on delete set null,
  first_name        text not null,
  last_name         text not null,
  position          text,
  area              text,
  email             citext,
  phone             text,
  whatsapp          text,
  linkedin          text,
  influence_level   smallint check (influence_level between 1 and 5),
  decision_role     text check (decision_role in ('Decisor','Influenciador','Usuario','Comprador','Prescriptor','Técnico',
                      'Gerencia','Finanzas','Operaciones','Seguridad','Facility Management','Prevención de Riesgos','TI')),
  contact_type      text,
  relationship      text check (relationship in ('Fría','Tibia','Fuerte','Sponsor')),
  last_contact_at   date,
  next_contact_at   date,
  legal_basis       text not null default 'interés legítimo B2B',   -- Ley 21.719
  opted_out         boolean not null default false,
  notes             text,
  owner_id          uuid references crm.users(id) default auth.uid(),
  created_at        timestamptz not null default now(),
  updated_at        timestamptz not null default now(),
  deleted_at        timestamptz
);
create index contacts_company_idx on crm.contacts(company_id);
create index contacts_email_idx   on crm.contacts(email);
create index contacts_name_trgm   on crm.contacts using gin ((first_name || ' ' || last_name) gin_trgm_ops);

-- ---------------------------------------------------------------------
-- 6. LEADS
-- ---------------------------------------------------------------------
create table crm.leads (
  id                        uuid primary key default gen_random_uuid(),
  company_id                uuid not null references crm.companies(id),
  contact_id                uuid references crm.contacts(id) on delete set null,
  source                    text,
  potential                 text check (potential in ('Alto','Medio','Bajo')),
  detected_problem          text,
  potential_service         text,
  stage_id                  uuid not null references crm.pipeline_stages(id),
  owner_id                  uuid not null references crm.users(id),
  next_action               text,
  next_action_date          date,
  probability               int check (probability between 0 and 100),
  score_inputs              jsonb not null default '{}',   -- {"sitios":"1","necesidad":"2",...}
  score                     int not null default 0 check (score between 0 and 100),
  score_grade               char(1) not null default 'C' check (score_grade in ('A','B','C')),
  score_breakdown           jsonb not null default '[]',
  disqualify_reason         text,
  disqualify_comment        text,
  converted_opportunity_id  uuid,
  converted_at              timestamptz,
  created_at                timestamptz not null default now(),
  updated_at                timestamptz not null default now(),
  deleted_at                timestamptz
);
create index leads_owner_stage_idx on crm.leads(owner_id, stage_id);
create index leads_next_action_idx on crm.leads(next_action_date);
create index leads_grade_idx       on crm.leads(score_grade);

create table crm.lead_services (
  lead_id     uuid not null references crm.leads(id) on delete cascade,
  service_id  uuid not null references crm.services(id),
  primary key (lead_id, service_id)
);

-- Cálculo de score: industria (automática) + opciones elegidas. Deja el desglose.
create or replace function crm.compute_lead_score() returns trigger
language plpgsql as $$
declare
  v_parts jsonb := '[]'; v_total int := 0; v_ind_pts int := 0; v_ind_name text;
  r record;
begin
  select i.name, i.score_points into v_ind_name, v_ind_pts
  from crm.companies c left join crm.industries i on i.id = c.industry_id
  where c.id = new.company_id;
  v_total := coalesce(v_ind_pts,0);
  v_parts := v_parts || jsonb_build_object('variable','industria','label',coalesce(v_ind_name,'sin dato'),'points',coalesce(v_ind_pts,0));
  for r in
    select so.variable, so.label, so.points
    from jsonb_each_text(new.score_inputs) e
    join crm.scoring_options so on so.variable = e.key and so.sort_order = e.value::int and so.is_active
  loop
    v_total := v_total + r.points;
    v_parts := v_parts || jsonb_build_object('variable',r.variable,'label',r.label,'points',r.points);
  end loop;
  new.score := least(v_total,100);
  new.score_grade := case when new.score >= 70 then 'A' when new.score >= 45 then 'B' else 'C' end;
  new.score_breakdown := v_parts;
  return new;
end $$;
create trigger leads_score before insert or update of score_inputs, company_id on crm.leads
  for each row execute function crm.compute_lead_score();

-- ---------------------------------------------------------------------
-- 7. OPORTUNIDADES
-- ---------------------------------------------------------------------
create sequence crm.opp_code_seq;

create table crm.opportunities (
  id                          uuid primary key default gen_random_uuid(),
  code                        text not null unique
                              default 'OP-' || to_char(now(),'YYYY') || '-' || lpad(nextval('crm.opp_code_seq')::text,4,'0'),
  name                        text not null,
  company_id                  uuid not null references crm.companies(id),
  site_id                     uuid references crm.sites(id) on delete set null,
  lead_id                     uuid references crm.leads(id) on delete set null,
  owner_id                    uuid not null references crm.users(id),
  stage_id                    uuid not null references crm.pipeline_stages(id),
  substage                    text,
  status                      crm.opp_status not null default 'abierta',
  probability                 int not null check (probability between 0 and 100),
  probability_override_reason text,
  currency                    crm.currency_code not null default 'CLP',
  one_time_amount             numeric(18,2) not null default 0 check (one_time_amount >= 0),
  monthly_amount              numeric(18,2) not null default 0 check (monthly_amount >= 0),
  term_months                 int not null default 0 check (term_months >= 0),
  tcv_original                numeric(20,2) generated always as (one_time_amount + monthly_amount * term_months) stored,
  expected_close_date         date not null,
  need                        text,
  customer_pain               text,
  budget_status               text,
  current_provider            text,
  competitors_text            text,
  strategy                    text,
  risks                       text,
  next_action                 text,
  next_action_date            date,
  stage_entered_at            date not null default current_date,
  last_activity_at            date,
  loss_reason_id              uuid references crm.loss_reasons(id),
  loss_comment                text,
  won_at                      date,
  won_evidence                text,
  lost_at                     date,
  postponed_until             date,
  is_tender                   boolean not null default false,
  created_by                  uuid references crm.users(id) default auth.uid(),
  created_at                  timestamptz not null default now(),
  updated_at                  timestamptz not null default now(),
  deleted_at                  timestamptz,
  -- Reglas de cierre obligatorias
  constraint opp_lost_requires_reason  check (status <> 'perdida' or (loss_reason_id is not null and length(coalesce(loss_comment,'')) >= 10)),
  constraint opp_won_requires_evidence check (status <> 'ganada' or (won_at is not null and won_evidence is not null)),
  constraint opp_postponed_requires_date check (status <> 'postergada' or postponed_until is not null),
  constraint opp_override_justified    check (probability_override_reason is null or length(probability_override_reason) >= 5)
);
create index opp_owner_status_idx on crm.opportunities(owner_id, status, stage_id);
create index opp_close_idx        on crm.opportunities(expected_close_date) where status = 'abierta';
create index opp_next_action_idx  on crm.opportunities(next_action_date)   where status = 'abierta';
create index opp_company_idx      on crm.opportunities(company_id);
create index opp_name_trgm        on crm.opportunities using gin (name gin_trgm_ops);

alter table crm.leads add constraint leads_converted_opp_fk
  foreign key (converted_opportunity_id) references crm.opportunities(id) on delete set null;

create table crm.opportunity_contacts (
  opportunity_id  uuid not null references crm.opportunities(id) on delete cascade,
  contact_id      uuid not null references crm.contacts(id) on delete cascade,
  role_in_deal    text,
  is_primary      boolean not null default false,
  primary key (opportunity_id, contact_id)
);

create table crm.opportunity_services (
  id               uuid primary key default gen_random_uuid(),
  opportunity_id   uuid not null references crm.opportunities(id) on delete cascade,
  service_id       uuid not null references crm.services(id),
  site_id          uuid references crm.sites(id) on delete set null,
  quantity         numeric(12,2) default 1,
  one_time_amount  numeric(18,2) default 0,
  monthly_amount   numeric(18,2) default 0
);
create index opp_services_opp_idx on crm.opportunity_services(opportunity_id);

create table crm.opportunity_competitors (
  opportunity_id  uuid not null references crm.opportunities(id) on delete cascade,
  competitor_id   uuid not null references crm.competitors(id),
  position        text,
  notes           text,
  primary key (opportunity_id, competitor_id)
);

create table crm.opportunity_stage_history (
  id              uuid primary key default gen_random_uuid(),
  opportunity_id  uuid not null references crm.opportunities(id) on delete cascade,
  from_stage_id   uuid references crm.pipeline_stages(id),
  to_stage_id     uuid not null references crm.pipeline_stages(id),
  days_in_stage   int,
  changed_by      uuid references crm.users(id) default auth.uid(),
  changed_at      timestamptz not null default now()
);
create index osh_opp_idx on crm.opportunity_stage_history(opportunity_id, changed_at);

-- Registra cambios de etapa y reinicia el contador de días en etapa.
create or replace function crm.track_stage_change() returns trigger
language plpgsql as $$
begin
  if tg_op = 'INSERT' then
    insert into crm.opportunity_stage_history(opportunity_id, from_stage_id, to_stage_id, days_in_stage)
    values (new.id, null, new.stage_id, 0);
  elsif new.stage_id is distinct from old.stage_id then
    insert into crm.opportunity_stage_history(opportunity_id, from_stage_id, to_stage_id, days_in_stage)
    values (new.id, old.stage_id, new.stage_id, current_date - old.stage_entered_at);
    new.stage_entered_at := current_date;
  end if;
  return new;
end $$;
create trigger opp_stage_before before update of stage_id on crm.opportunities
  for each row execute function crm.track_stage_change();
create or replace function crm.track_stage_insert() returns trigger
language plpgsql as $$ begin
  insert into crm.opportunity_stage_history(opportunity_id, from_stage_id, to_stage_id, days_in_stage)
  values (new.id, null, new.stage_id, 0); return null; end $$;
create trigger opp_stage_after_insert after insert on crm.opportunities
  for each row execute function crm.track_stage_insert();

-- ---------------------------------------------------------------------
-- 8. OFERTAS (básicas en Fase 1; ítems y márgenes llegan en Fase 2)
-- ---------------------------------------------------------------------
create sequence crm.offer_code_seq;

create table crm.offers (
  id              uuid primary key default gen_random_uuid(),
  code            text not null unique
                  default 'OF-' || to_char(now(),'YYYY') || '-' || lpad(nextval('crm.offer_code_seq')::text,3,'0'),
  opportunity_id  uuid not null references crm.opportunities(id),
  owner_id        uuid not null references crm.users(id),
  status          crm.offer_status not null default 'Borrador',
  sent_at         date,
  last_followup_at date,
  valid_until     date,
  created_at      timestamptz not null default now(),
  updated_at      timestamptz not null default now(),
  constraint offer_sent_has_date check (status not in ('Enviada','En seguimiento','Revisada por cliente') or sent_at is not null)
);
create index offers_status_idx on crm.offers(status, valid_until);
create index offers_opp_idx    on crm.offers(opportunity_id);

create table crm.offer_versions (
  id              uuid primary key default gen_random_uuid(),
  offer_id        uuid not null references crm.offers(id) on delete cascade,
  version_number  int not null check (version_number > 0),
  currency        crm.currency_code not null default 'CLP',
  net_amount      numeric(18,2) not null check (net_amount >= 0),
  vat_rate        numeric(5,4) not null default 0.19,
  total_amount    numeric(20,2) generated always as (round(net_amount * (1 + vat_rate), 2)) stored,
  change_summary  text,
  is_locked       boolean not null default false,
  sent_at         timestamptz,
  created_by      uuid references crm.users(id) default auth.uid(),
  created_at      timestamptz not null default now(),
  unique (offer_id, version_number)
);

-- Una versión enviada es inmutable.
create or replace function crm.protect_locked_version() returns trigger
language plpgsql as $$
begin
  if old.is_locked then
    raise exception 'La versión % de la oferta está bloqueada: cree una nueva versión', old.version_number;
  end if;
  return new;
end $$;
create trigger offer_versions_lock before update or delete on crm.offer_versions
  for each row execute function crm.protect_locked_version();

-- ---------------------------------------------------------------------
-- 9. ACTIVIDADES, REUNIONES, TAREAS Y NOTAS (polimórficas)
-- ---------------------------------------------------------------------
create table crm.activities (
  id                uuid primary key default gen_random_uuid(),
  entity_type       crm.entity_type not null,
  entity_id         uuid not null,
  company_id        uuid references crm.companies(id),     -- desnormalizado para Cliente 360°
  activity_type     text not null check (activity_type in ('Llamada','Email','WhatsApp','Reunión','Videollamada',
                      'Visita a terreno','Presentación','Envío de oferta','Negociación','Nota')),
  occurred_on       date not null default current_date,    -- futura = agendada
  user_id           uuid not null references crm.users(id) default auth.uid(),
  description       text not null,
  result            text,
  next_action       text,
  next_action_date  date,
  geo_lat           numeric(9,6),
  geo_lng           numeric(9,6),
  created_at        timestamptz not null default now()
);
create index act_entity_idx  on crm.activities(entity_type, entity_id, occurred_on desc);
create index act_company_idx on crm.activities(company_id, occurred_on desc);
create index act_user_idx    on crm.activities(user_id, occurred_on desc);

create table crm.meeting_attendees (
  activity_id  uuid not null references crm.activities(id) on delete cascade,
  user_id      uuid references crm.users(id),
  contact_id   uuid references crm.contacts(id),
  check (user_id is not null or contact_id is not null)
);

create table crm.tasks (
  id            uuid primary key default gen_random_uuid(),
  title         text not null,
  entity_type   crm.entity_type,
  entity_id     uuid,
  assignee_id   uuid not null references crm.users(id),
  created_by    uuid references crm.users(id) default auth.uid(),
  priority      crm.task_priority not null default 'Media',
  due_date      date not null,
  reminder_at   timestamptz,
  status        crm.task_status not null default 'Pendiente',
  completed_at  timestamptz,
  comments      text,
  source        text,                 -- 'manual', 'automatizacion:minuta', ...
  created_at    timestamptz not null default now(),
  updated_at    timestamptz not null default now()
);
create index tasks_assignee_idx on crm.tasks(assignee_id, status, due_date);
create index tasks_entity_idx   on crm.tasks(entity_type, entity_id);

create table crm.notes (
  id           uuid primary key default gen_random_uuid(),
  entity_type  crm.entity_type not null,
  entity_id    uuid not null,
  body         text not null,
  is_pinned    boolean not null default false,
  user_id      uuid not null references crm.users(id) default auth.uid(),
  created_at   timestamptz not null default now()
);
create index notes_entity_idx on crm.notes(entity_type, entity_id);

-- Al registrar una actividad: actualiza última actividad, próxima acción
-- y aplica automatizaciones (tarea de minuta, oferta a "En seguimiento").
create or replace function crm.after_activity() returns trigger
language plpgsql security definer set search_path = crm as $$
begin
  if new.occurred_on <= current_date then
    update crm.companies set last_activity_at = greatest(coalesce(last_activity_at, new.occurred_on), new.occurred_on)
      where id = new.company_id;
    if new.entity_type = 'opportunity' then
      update crm.opportunities set last_activity_at = greatest(coalesce(last_activity_at,new.occurred_on), new.occurred_on),
        next_action = coalesce(new.next_action, next_action),
        next_action_date = coalesce(new.next_action_date, next_action_date)
      where id = new.entity_id;
    elsif new.entity_type = 'offer' then
      update crm.offers set last_followup_at = new.occurred_on,
        status = case when status = 'Enviada' then 'En seguimiento' else status end
      where id = new.entity_id;
      update crm.opportunities o set last_activity_at = greatest(coalesce(o.last_activity_at,new.occurred_on), new.occurred_on)
      from crm.offers f where f.id = new.entity_id and o.id = f.opportunity_id;
    elsif new.entity_type = 'lead' then
      update crm.leads set next_action = coalesce(new.next_action, next_action),
        next_action_date = coalesce(new.next_action_date, next_action_date)
      where id = new.entity_id;
    end if;
    if new.activity_type in ('Reunión','Videollamada','Visita a terreno','Presentación') then
      insert into crm.tasks(title, entity_type, entity_id, assignee_id, priority, due_date, source)
      values ('Enviar minuta y acordar próximo paso', new.entity_type, new.entity_id, new.user_id, 'Alta',
              current_date + 1, 'automatizacion:minuta');
    end if;
  end if;
  return null;
end $$;
create trigger activities_after after insert on crm.activities
  for each row execute function crm.after_activity();

-- ---------------------------------------------------------------------
-- 10. ALERTAS Y NOTIFICACIONES
-- ---------------------------------------------------------------------
create table crm.alerts (
  id                  uuid primary key default gen_random_uuid(),
  rule_code           text not null,
  entity_type         crm.entity_type not null,
  entity_id           uuid not null,
  owner_id            uuid not null references crm.users(id),
  severity            crm.alert_severity not null,
  title               text not null,
  message             text not null,
  recommended_action  text,
  status              crm.alert_status not null default 'activa',
  snoozed_until       date,
  created_at          timestamptz not null default now(),
  resolved_at         timestamptz
);
create unique index alerts_dedup_uq on crm.alerts(rule_code, entity_id) where status = 'activa';
create index alerts_owner_idx on crm.alerts(owner_id, status);

create table crm.notifications (
  id          uuid primary key default gen_random_uuid(),
  user_id     uuid not null references crm.users(id),
  alert_id    uuid references crm.alerts(id) on delete cascade,
  channel     text not null check (channel in ('in-app','email','push')),
  sent_at     timestamptz not null default now(),
  read_at     timestamptz
);
create index notifications_user_idx on crm.notifications(user_id, read_at);

-- ---------------------------------------------------------------------
-- 11. AUDITORÍA (append-only)
-- ---------------------------------------------------------------------
create table crm.audit_logs (
  id           bigint generated always as identity primary key,
  occurred_at  timestamptz not null default now(),
  user_id      uuid default auth.uid(),
  action       text not null check (action in ('create','update','delete','export','login')),
  table_name   text not null,
  record_id    uuid,
  field        text,
  old_value    text,
  new_value    text
);
create index audit_record_idx on crm.audit_logs(table_name, record_id, occurred_at desc);
create index audit_user_idx   on crm.audit_logs(user_id, occurred_at desc);

create or replace function crm.audit_row() returns trigger
language plpgsql security definer set search_path = crm as $$
declare k text; o jsonb; n jsonb;
begin
  if tg_op = 'INSERT' then
    insert into crm.audit_logs(action, table_name, record_id) values ('create', tg_table_name, new.id);
    return null;
  elsif tg_op = 'DELETE' then
    insert into crm.audit_logs(action, table_name, record_id, old_value) values ('delete', tg_table_name, old.id, to_jsonb(old)::text);
    return null;
  end if;
  o := to_jsonb(old); n := to_jsonb(new);
  for k in select jsonb_object_keys(n) loop
    if k not in ('updated_at','last_activity_at','tcv_original','rut_norm') and (o->k) is distinct from (n->k) then
      insert into crm.audit_logs(action, table_name, record_id, field, old_value, new_value)
      values ('update', tg_table_name, new.id, k, o->>k, n->>k);
    end if;
  end loop;
  return null;
end $$;

create or replace function crm.audit_immutable() returns trigger
language plpgsql as $$ begin raise exception 'La auditoría no se puede modificar ni borrar'; end $$;
create trigger audit_logs_immutable before update or delete on crm.audit_logs
  for each row execute function crm.audit_immutable();

do $$ declare t text; begin
  foreach t in array array['companies','sites','contacts','leads','opportunities','offers','tasks','sales_targets','followup_rules'] loop
    execute format('create trigger %I_audit after insert or update or delete on crm.%I for each row execute function crm.audit_row()', t, t);
  end loop;
  foreach t in array array['users','companies','contacts','leads','opportunities','offers','tasks'] loop
    execute format('create trigger %I_touch before update on crm.%I for each row execute function crm.touch_updated_at()', t, t);
  end loop;
end $$;

-- ---------------------------------------------------------------------
-- 12. VISTAS DE NEGOCIO (regla fundamental, pipeline, follow-up)
-- ---------------------------------------------------------------------
create or replace view crm.v_opportunities with (security_invoker = on) as
select o.*,
  s.name  as stage_name, s.sort_order as stage_order, s.default_probability,
  round(o.tcv_original * coalesce(crm.rate_clp(o.currency), 0)) as tcv_clp,
  round(o.tcv_original * coalesce(crm.rate_clp(o.currency), 0) * o.probability / 100.0) as weighted_clp,
  case when o.status = 'ganada' then 'Closed Won'
       when o.probability >= 80 then 'Commit'
       when o.probability >= 50 then 'Best Case'
       else 'Pipeline' end as forecast_category,
  current_date - o.stage_entered_at as days_in_stage,
  current_date - coalesce(o.last_activity_at, o.created_at::date) as days_without_activity,
  case when o.status <> 'abierta' then '{}'::text[] else array_remove(array[
    case when o.owner_id is null then 'responsable' end,
    case when o.tcv_original <= 0 then 'monto' end,
    case when o.probability <= 0 then 'probabilidad' end,
    case when o.next_action is null or o.next_action = '' then 'próxima acción' end,
    case when o.next_action_date is null then 'fecha de próxima acción'
         when o.next_action_date < current_date then 'próxima acción vencida' end
  ], null) end as fundamental_missing
from crm.opportunities o
join crm.pipeline_stages s on s.id = o.stage_id
where o.deleted_at is null;

create or replace view crm.v_offer_followup with (security_invoker = on) as
select f.*, v.version_number, v.net_amount, v.total_amount,
  current_date - greatest(f.sent_at, coalesce(f.last_followup_at, f.sent_at)) as days_since_touch,
  case
    when f.status not in ('Enviada','En seguimiento','Revisada por cliente') then null
    when current_date - greatest(f.sent_at, coalesce(f.last_followup_at, f.sent_at)) >= (select days from crm.followup_rules where code='offer_red'    and is_active) then 'roja'
    when current_date - greatest(f.sent_at, coalesce(f.last_followup_at, f.sent_at)) >= (select days from crm.followup_rules where code='offer_yellow' and is_active) then 'amarilla'
    when current_date - greatest(f.sent_at, coalesce(f.last_followup_at, f.sent_at)) >= (select days from crm.followup_rules where code='offer_remind' and is_active) then 'recordar'
    else 'al día' end as followup_level
from crm.offers f
join lateral (select * from crm.offer_versions ov where ov.offer_id = f.id order by version_number desc limit 1) v on true;

-- ---------------------------------------------------------------------
-- 13. MOTOR DE ALERTAS (se ejecuta con pg_cron cada hora)
-- ---------------------------------------------------------------------
create or replace function crm.run_alert_engine() returns int
language plpgsql security definer set search_path = crm as $$
declare n int := 0; r_warn int; r_risk int; r_stall int; r_offer_exp int;
begin
  select days into r_warn  from crm.followup_rules where code='opp_no_activity_warn' and is_active;
  select days into r_risk  from crm.followup_rules where code='opp_no_activity_risk' and is_active;
  select days into r_stall from crm.followup_rules where code='opp_stalled'          and is_active;
  select days into r_offer_exp from crm.followup_rules where code='offer_expiring'   and is_active;

  -- Resolver alertas cuya condición ya no se cumple
  update crm.alerts a set status='resuelta', resolved_at=now()
  where a.status='activa' and a.entity_type='opportunity' and not exists (
    select 1 from crm.v_opportunities v where v.id=a.entity_id and v.status='abierta' and (
      (a.rule_code='opp_fundamental' and cardinality(v.fundamental_missing)>0) or
      (a.rule_code='opp_no_activity' and v.days_without_activity >= r_warn) or
      (a.rule_code='opp_stalled'     and v.days_in_stage >= r_stall) or
      (a.rule_code='opp_close_past'  and v.expected_close_date < current_date)));
  update crm.alerts a set status='resuelta', resolved_at=now()
  where a.status='activa' and a.rule_code='offer_followup' and not exists (
    select 1 from crm.v_offer_followup v where v.id=a.entity_id and v.followup_level in ('recordar','amarilla','roja'));

  -- Regla fundamental
  insert into crm.alerts(rule_code, entity_type, entity_id, owner_id, severity, title, message, recommended_action)
  select 'opp_fundamental','opportunity', v.id, v.owner_id, 'roja', 'Regla fundamental incompleta: '||v.name,
         'Falta '||array_to_string(v.fundamental_missing, ', '), 'Completar la oportunidad'
  from crm.v_opportunities v where v.status='abierta' and cardinality(v.fundamental_missing)>0
  on conflict (rule_code, entity_id) where status='activa' do update set message=excluded.message;
  get diagnostics n = row_count;

  -- Sin actividad (amarilla / roja según umbral)
  insert into crm.alerts(rule_code, entity_type, entity_id, owner_id, severity, title, message, recommended_action)
  select 'opp_no_activity','opportunity', v.id, v.owner_id,
         case when v.days_without_activity >= r_risk then 'roja' else 'amarilla' end::crm.alert_severity,
         'Oportunidad sin actividad: '||v.name, v.days_without_activity||' días sin actividad', 'Contactar al cliente'
  from crm.v_opportunities v where v.status='abierta' and v.days_without_activity >= r_warn
  on conflict (rule_code, entity_id) where status='activa' do update set severity=excluded.severity, message=excluded.message;

  -- Estancada
  insert into crm.alerts(rule_code, entity_type, entity_id, owner_id, severity, title, message, recommended_action)
  select 'opp_stalled','opportunity', v.id, v.owner_id, 'amarilla', 'Oportunidad estancada: '||v.name,
         v.days_in_stage||' días en '||v.stage_name, 'Definir cómo avanzar de etapa'
  from crm.v_opportunities v where v.status='abierta' and v.days_in_stage >= r_stall
  on conflict (rule_code, entity_id) where status='activa' do update set message=excluded.message;

  -- Fecha de cierre vencida
  insert into crm.alerts(rule_code, entity_type, entity_id, owner_id, severity, title, message, recommended_action)
  select 'opp_close_past','opportunity', v.id, v.owner_id, 'amarilla', 'Fecha de cierre vencida: '||v.name,
         'Cierre estimado '||to_char(v.expected_close_date,'DD-MM-YYYY'), 'Reprogramar la fecha de cierre'
  from crm.v_opportunities v where v.status='abierta' and v.expected_close_date < current_date
  on conflict (rule_code, entity_id) where status='activa' do nothing;

  -- Seguimiento de ofertas
  insert into crm.alerts(rule_code, entity_type, entity_id, owner_id, severity, title, message, recommended_action)
  select 'offer_followup','offer', f.id, f.owner_id,
         case f.followup_level when 'roja' then 'roja' when 'amarilla' then 'amarilla' else 'info' end::crm.alert_severity,
         'Oferta sin seguimiento: '||f.code, f.days_since_touch||' días desde el envío o último seguimiento',
         'Llamar al cliente y registrar el seguimiento'
  from crm.v_offer_followup f where f.followup_level in ('recordar','amarilla','roja')
  on conflict (rule_code, entity_id) where status='activa' do update set severity=excluded.severity, message=excluded.message;

  -- Oferta próxima a vencer
  insert into crm.alerts(rule_code, entity_type, entity_id, owner_id, severity, title, message, recommended_action)
  select 'offer_expiring','offer', f.id, f.owner_id, 'amarilla', 'Oferta próxima a vencer: '||f.code,
         'Vence el '||to_char(f.valid_until,'DD-MM-YYYY'), 'Extender vigencia o cerrar la negociación'
  from crm.offers f where f.valid_until between current_date and current_date + r_offer_exp
    and f.status not in ('Borrador','Aceptada','Rechazada','Vencida','Cancelada')
  on conflict (rule_code, entity_id) where status='activa' do nothing;

  return n;
end $$;
-- En Supabase: select cron.schedule('alert-engine', '0 * * * *', $$select crm.run_alert_engine()$$);

-- ---------------------------------------------------------------------
-- 14. ROW LEVEL SECURITY
-- ---------------------------------------------------------------------
do $$ declare t text; begin
  foreach t in array array['users','teams','role_permissions','industries','service_categories','services','pipeline_stages',
    'loss_reasons','competitors','scoring_options','followup_rules','sales_targets','exchange_rates','companies','sites','contacts',
    'leads','lead_services','opportunities','opportunity_contacts','opportunity_services','opportunity_competitors',
    'opportunity_stage_history','offers','offer_versions','activities','meeting_attendees','tasks','notes','alerts',
    'notifications','audit_logs'] loop
    execute format('alter table crm.%I enable row level security', t);
  end loop;
end $$;

-- Catálogos: lectura para usuarios activos, escritura solo gerencia/admin
do $$ declare t text; begin
  foreach t in array array['industries','service_categories','services','pipeline_stages','loss_reasons','competitors',
                           'scoring_options','followup_rules','exchange_rates','role_permissions','teams'] loop
    execute format('create policy %I_read on crm.%I for select using (crm.is_active_user())', t, t);
    execute format('create policy %I_write on crm.%I for all using (crm.is_manager()) with check (crm.is_manager())', t, t);
  end loop;
end $$;

create policy users_read   on crm.users for select using (crm.is_active_user());
create policy users_admin  on crm.users for all using (crm.current_app_role() = 'admin') with check (crm.current_app_role() = 'admin');
create policy targets_read on crm.sales_targets for select using (crm.is_manager() or user_id = auth.uid());
create policy targets_write on crm.sales_targets for all using (crm.is_manager()) with check (crm.is_manager());

-- Empresas, sitios y contactos: todos los activos leen (evita duplicar cuentas); edita el dueño o gerencia
create policy companies_read   on crm.companies for select using (crm.is_active_user() and deleted_at is null);
create policy companies_insert on crm.companies for insert with check (crm.current_app_role() in ('admin','gerente','bd','kam','backoffice'));
create policy companies_update on crm.companies for update using (crm.is_manager() or owner_id = auth.uid() or crm.current_app_role()='backoffice');
create policy companies_delete on crm.companies for delete using (crm.is_manager());

create policy sites_read  on crm.sites for select using (crm.is_active_user());
create policy sites_write on crm.sites for all
  using (crm.is_manager() or exists (select 1 from crm.companies c where c.id = company_id and c.owner_id = auth.uid()))
  with check (crm.current_app_role() in ('admin','gerente','bd','kam','backoffice'));

create policy contacts_read  on crm.contacts for select using (crm.is_active_user() and deleted_at is null);
create policy contacts_write on crm.contacts for all
  using (crm.is_manager() or owner_id = auth.uid() or exists (select 1 from crm.companies c where c.id = company_id and c.owner_id = auth.uid()))
  with check (crm.current_app_role() in ('admin','gerente','bd','kam','backoffice'));

-- Leads y oportunidades: el ejecutivo ve lo suyo; gerencia, back office y consulta ven todo
create policy leads_read   on crm.leads for select using (crm.can_read_all() or owner_id = auth.uid());
create policy leads_insert on crm.leads for insert with check (crm.is_manager() or owner_id = auth.uid());
create policy leads_update on crm.leads for update using (crm.is_manager() or owner_id = auth.uid());
create policy lead_services_all on crm.lead_services for all
  using (exists (select 1 from crm.leads l where l.id = lead_id and (crm.can_read_all() or l.owner_id = auth.uid())));

create policy opp_read   on crm.opportunities for select using (crm.can_read_all() or owner_id = auth.uid()
                           or crm.current_app_role() in ('ingenieria','operaciones'));
create policy opp_insert on crm.opportunities for insert with check (crm.is_manager() or owner_id = auth.uid());
create policy opp_update on crm.opportunities for update using (crm.is_manager() or owner_id = auth.uid())
                           with check (crm.is_manager() or owner_id = auth.uid());
create policy opp_delete on crm.opportunities for delete using (crm.is_manager());

do $$ declare t text; begin
  foreach t in array array['opportunity_contacts','opportunity_services','opportunity_competitors','opportunity_stage_history'] loop
    execute format($f$create policy %I_all on crm.%I for all using (exists (select 1 from crm.opportunities o
      where o.id = opportunity_id and (crm.can_read_all() or o.owner_id = auth.uid())))$f$, t, t);
  end loop;
end $$;

create policy offers_read  on crm.offers for select using (crm.can_read_all() or owner_id = auth.uid());
create policy offers_write on crm.offers for all using (crm.is_manager() or owner_id = auth.uid() or crm.current_app_role()='backoffice');
create policy offer_versions_all on crm.offer_versions for all using (exists (select 1 from crm.offers f
  where f.id = offer_id and (crm.can_read_all() or f.owner_id = auth.uid())));

create policy act_read   on crm.activities for select using (crm.can_read_all() or user_id = auth.uid()
  or exists (select 1 from crm.companies c where c.id = company_id and c.owner_id = auth.uid()));
create policy act_insert on crm.activities for insert with check (user_id = auth.uid());
create policy act_update on crm.activities for update using (user_id = auth.uid() or crm.is_manager());
create policy meeting_att_all on crm.meeting_attendees for all using (exists (select 1 from crm.activities a
  where a.id = activity_id and (crm.can_read_all() or a.user_id = auth.uid())));

create policy tasks_read  on crm.tasks for select using (crm.can_read_all() or assignee_id = auth.uid() or created_by = auth.uid());
create policy tasks_write on crm.tasks for all using (crm.is_manager() or assignee_id = auth.uid() or created_by = auth.uid());

create policy notes_all on crm.notes for all using (crm.can_read_all() or user_id = auth.uid());
create policy alerts_own on crm.alerts for select using (crm.is_manager() or owner_id = auth.uid());
create policy alerts_upd on crm.alerts for update using (crm.is_manager() or owner_id = auth.uid());
create policy notif_own on crm.notifications for all using (user_id = auth.uid());
create policy audit_read on crm.audit_logs for select using (crm.is_manager());

-- Las exportaciones se registran desde el backend con esta función
create or replace function crm.log_export(p_table text, p_rows int) returns void
language sql security definer set search_path = crm as $$
  insert into crm.audit_logs(action, table_name, field, new_value) values ('export', p_table, 'filas', p_rows::text)
$$;

-- ---------------------------------------------------------------------
-- 15. DATOS INICIALES (catálogos)
-- ---------------------------------------------------------------------
insert into crm.industries(name, is_strategic, score_points, sort_order) values
 ('Energía',true,12,1),('Minería',true,12,2),('Puertos',true,12,3),('Data Centers',true,12,4),
 ('Industria',false,8,5),('Logística',false,8,6),('Centros de distribución',false,8,7),
 ('Retail',false,4,8),('Salud',false,4,9),('Educación',false,4,10),('Construcción',false,4,11),('Inmobiliario',false,4,12),
 ('Agroindustria',false,4,13),('Smart Cities',false,4,14),('Banca / Finanzas',false,4,15),('Servicios',false,4,16),
 ('Sector público',false,4,17),('Otros',false,4,18);

insert into crm.service_categories(name, sort_order) values
 ('Seguridad física',1),('Seguridad electrónica',2),('Monitoreo',3),('Tecnología',4),('Servicios',5);

insert into crm.services(category_id, name, billing_type)
select c.id, s.name, s.bt from (values
 ('Seguridad física','Guardias','recurrente'),('Seguridad física','Supervisión','recurrente'),
 ('Seguridad física','Seguridad especializada','recurrente'),('Seguridad física','Servicios marítimos','recurrente'),
 ('Seguridad electrónica','CCTV','unico'),('Seguridad electrónica','Control de acceso','unico'),
 ('Seguridad electrónica','Intrusión / alarmas','unico'),('Seguridad electrónica','Cerco eléctrico','unico'),
 ('Seguridad electrónica','Analítica de video','mixto'),('Seguridad electrónica','LPR','unico'),
 ('Seguridad electrónica','Radares','unico'),('Seguridad electrónica','Detección','unico'),
 ('Seguridad electrónica','Integración de sistemas','unico'),
 ('Monitoreo','Monitoreo remoto 24/7','recurrente'),('Monitoreo','SOC','recurrente'),
 ('Monitoreo','Video vigilancia','recurrente'),('Monitoreo','Monitoreo de alarmas','recurrente'),
 ('Tecnología','Comunicaciones','mixto'),('Tecnología','Corrientes débiles','unico'),
 ('Tecnología','Plataformas','mixto'),('Tecnología','IoT','mixto'),
 ('Servicios','Mantenimiento preventivo','recurrente'),('Servicios','Mantenimiento correctivo','recurrente'),
 ('Servicios','Proyectos','unico'),('Servicios','Ingeniería','unico')
) s(cat, name, bt) join crm.service_categories c on c.name = s.cat;

insert into crm.pipeline_stages(pipeline, code, name, sort_order, default_probability, is_closed, is_won, exit_criteria) values
 ('opportunity','identificada','Identificada',1,10,false,false,'["Empresa","Contacto","Necesidad preliminar"]'),
 ('opportunity','calificada','Calificada',2,20,false,false,'["Decisor identificado","Necesidad confirmada","Plazo estimado"]'),
 ('opportunity','levantamiento','Levantamiento',3,30,false,false,'["Visita o levantamiento registrado"]'),
 ('opportunity','ingenieria','Ingeniería / Solución',4,40,false,false,'["Solución técnica definida"]'),
 ('opportunity','oferta','Oferta',5,50,false,false,'["Oferta enviada con versión y fecha"]'),
 ('opportunity','seguimiento','Seguimiento',6,60,false,false,'["Feedback del cliente registrado"]'),
 ('opportunity','negociacion','Negociación',7,80,false,false,'["Condiciones finales acordadas"]'),
 ('opportunity','adjudicada','Adjudicada',8,100,true,true,'["OC, carta de adjudicación o contrato"]'),
 ('lead','identificado','Identificado',1,5,false,false,'[]'),('lead','investigacion','Investigación',2,5,false,false,'[]'),
 ('lead','contacto_inicial','Contacto inicial',3,10,false,false,'[]'),('lead','contactado','Contactado',4,10,false,false,'[]'),
 ('lead','reunion_solicitada','Reunión solicitada',5,15,false,false,'[]'),('lead','reunion_realizada','Reunión realizada',6,20,false,false,'[]'),
 ('lead','calificacion','Calificación',7,25,false,false,'[]'),('lead','oportunidad_creada','Oportunidad creada',8,100,true,true,'[]'),
 ('lead','no_calificado','No calificado',9,0,true,false,'[]'),('lead','nurturing','Nurturing',10,5,false,false,'[]');

insert into crm.loss_reasons(name) values ('Precio'),('Competencia'),('Cliente postergó'),('Sin presupuesto'),('Proveedor actual'),
 ('Requerimiento técnico'),('Plazo'),('Condiciones comerciales'),('Falta de decisión'),('Licitación perdida'),('Proyecto cancelado'),('Otro');

insert into crm.scoring_options(variable, label, points, sort_order) values
 ('sitios','1 sitio',2,0),('sitios','2 a 4',6,1),('sitios','5 a 9',9,2),('sitios','10 o más',12,3),
 ('potencial','Menos de $50 MM',3,0),('potencial','$50 a $200 MM',7,1),('potencial','$200 a $500 MM',10,2),('potencial','Más de $500 MM',12,3),
 ('necesidad','Ninguna',0,0),('necesidad','Inferida',5,1),('necesidad','Explícita',10,2),
 ('decisor','Sin acceso',0,0),('decisor','Vía influenciador',5,1),('decisor','Directo',10,2),
 ('urgencia','Más de 6 meses',2,0),('urgencia','3 a 6 meses',5,1),('urgencia','Menos de 3 meses',8,2),
 ('fit','Bajo',0,0),('fit','Parcial',4,1),('fit','Solución integral',8,2),
 ('tamano','Pequeña',1,0),('tamano','Mediana',4,1),('tamano','Grande',6,2),
 ('renovacion','Sin fecha conocida',0,0),('renovacion','6 a 12 meses',3,1),('renovacion','Menos de 6 meses',6,2),
 ('complejidad','Baja',0,0),('complejidad','Media',3,1),('complejidad','Alta',6,2),
 ('cobertura','Fuera de cobertura',0,0),('cobertura','Parcial',3,1),('cobertura','Total',6,2),
 ('contrato','Sin proveedor',1,0),('contrato','Con proveedor',2,1),('contrato','Con proveedor insatisfecho',4,2);

insert into crm.followup_rules(code, entity_type, description, days, severity) values
 ('offer_remind','offer','Oferta enviada: recordar seguimiento',3,'info'),
 ('offer_yellow','offer','Oferta enviada: alerta amarilla',7,'amarilla'),
 ('offer_red','offer','Oferta enviada: alerta roja',14,'roja'),
 ('offer_expiring','offer','Oferta próxima a vencer',5,'amarilla'),
 ('opp_no_activity_warn','opportunity','Oportunidad sin actividad: alerta',10,'amarilla'),
 ('opp_no_activity_risk','opportunity','Oportunidad sin actividad: riesgo',20,'roja'),
 ('opp_stalled','opportunity','Oportunidad estancada en la etapa',30,'amarilla'),
 ('lead_no_activity','lead','Prospecto sin seguimiento',14,'amarilla');

insert into crm.role_permissions(role, module, can_read, can_create, can_update, can_delete, can_export, see_costs, visibility) values
 ('admin','*',true,true,true,true,true,true,'all'),
 ('gerente','*',true,true,true,true,true,true,'all'),
 ('bd','comercial',true,true,true,false,true,false,'own'),
 ('kam','comercial',true,true,true,false,true,false,'own'),
 ('ingenieria','comercial',true,false,false,false,false,false,'own'),
 ('operaciones','comercial',true,false,false,false,false,false,'own'),
 ('backoffice','comercial',true,true,true,false,true,true,'all'),
 ('consulta','comercial',true,false,false,false,false,false,'all');
